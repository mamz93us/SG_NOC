# Recruitment AI

AI screening of Teamtailor applicants: every CV of a job is read and scored against the job ad and the recruiter's must-haves, so a recruiter sees the best ten with reasons and can ask questions about the candidates. People decide; nothing is written to Teamtailor.

## Where it is

| What | Where | Permission |
|---|---|---|
| Jobs, screening switch, must-haves, top 10, all applicants, "Ask about these candidates" | NOC → **AI ▸ Recruitment AI** (also an **AI shortlist** button on a Teamtailor job page) | `use-recruitment-ai` |
| The same questions in the chat | home portal → Samir AI Assistant (tools appear only for permission holders) | `use-recruitment-ai` |
| Who may use it | NOC → **AI ▸ AI Access** | `manage-ai-access` |

Neither permission is part of any role by default. Super admins have both.

## Giving someone access

1. The person must have a NOC user: if they have never signed in, add them under **Users ▸ Add User ▸ From Entra** first.
2. **AI ▸ AI Access** → Recruitment AI → type their name → **Give access**.

Access is the `use-recruitment-ai` permission itself, so it also shows under Users ▸ Permissions. Removing it from someone who holds it through their role writes a *deny* for them. Every change is logged as `ai_access_granted` / `ai_access_revoked`, kept for the security retention window.

## Screening a job

1. **AI ▸ Recruitment AI** → open the job → optionally write **must-haves**, one per line.
2. **Switch on AI screening.** `recruitment:screen` (scheduler, every minute) reads the applicant list and screens applicants one by one; the page shows the progress.
3. The **Top 10** ranks screened applicants by score. Rejected applications are left out unless you include them.

How a score is made:

- The CV is downloaded from Teamtailor right before reading (the links are signed and expire). Teamtailor serves CVs as PDFs; text comes from `pdftotext`, and a scanned CV is read from images of its first three pages.
- One Azure OpenAI call per applicant returns the score, a summary, a check of each must-have with evidence, strengths, concerns, skills, languages, education and interview questions.
- The fit label comes from the score (85+ strong, 70+ good, 50+ possible). A must-have the CV clearly does **not** meet caps the score at 49.
- The instructions forbid using or mentioning name, photo, age, gender, marital status, religion, nationality or ethnicity, unless a must-have names one as a legal requirement of the role (for example Saudization), and they treat anything in a CV that looks like an instruction as text.

Screening again happens by itself when:

- the must-haves or the job ad change (everyone), or `CandidateScreener::INSTRUCTIONS_VERSION` is bumped;
- an applicant uploads a new CV in Teamtailor (that person).

**Screen all again** queues everyone by hand.

## Data

- `recruitment_jobs` — one row per job set up (must-haves, switch, counters).
- `recruitment_screenings` — one row per applicant: CV text and application answers (**encrypted**), the evaluation, the score.
- Ask conversations are AI conversations linked to the job and flagged `contains_candidate_data`. **AI ▸ Conversations** shows flagged conversations only to people who may use Recruitment AI.
- Kept until someone presses **Delete AI data** on the job, which deletes the screenings and the job's Ask conversations and switches screening off. Must-haves stay. Chat conversations in the home-portal assistant follow that assistant's own retention.
- Neither model is in the automatic audit (it would copy CV text into `activity_logs`); switching on/off, criteria changes, re-screens and deletes are logged by hand.

## Operations

```sh
php artisan recruitment:screen                 # one run (240 s budget)
php artisan recruitment:screen --job=6977263   # one job only
```

- Needs the Teamtailor API key with **Admin** scope (Settings → Teamtailor) and the AI assistant configured (Settings → AI Assistant). Without either, the command waits and says why.
- Azure HTTP 429 (throttling) ends the run; the next minute continues.
- A download that fails is retried on the next two runs, then the applicant shows **Could not read** with the reason. A file that is not a PDF, is password-protected or damaged fails at once.
- Cost is one chat call per applicant screened: roughly the CV (3–10 k characters), the ad and up to 1,800 output tokens.

## Not built

- Writing back to Teamtailor (tags, stages, rejections) — deliberately read-only.
- Automatic deletion after a period — data stays until deleted from the job page.
