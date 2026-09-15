# Recruitment AI

AI screening of Teamtailor applicants: every CV of a job is read and scored against the job ad, the recruiter's must-haves and, when they are set, the job's salary budget and office. A recruiter sees the best ten with reasons, each applicant's expected and current salary and distance to the office, and can ask questions about the candidates. People decide; nothing is written to Teamtailor.

## Where it is

| What | Where | Permission |
|---|---|---|
| Jobs, screening switch, must-haves, office and salary budget, top 10, all applicants, "Ask about these candidates" | NOC → **AI ▸ Recruitment AI** (also an **AI shortlist** button on a Teamtailor job page) | `use-recruitment-ai` |
| The same questions in the chat | home portal → Samir AI Assistant (tools appear only for permission holders) | `use-recruitment-ai` |
| Who may use it | NOC → **AI ▸ AI Access** | `manage-ai-access` |

Neither permission is part of any role by default. Super admins have both.

## Giving someone access

1. The person must have a NOC user: if they have never signed in, add them under **Users ▸ Add User ▸ From Entra** first.
2. **AI ▸ AI Access** → Recruitment AI → type their name → **Give access**.

Access is the `use-recruitment-ai` permission itself, so it also shows under Users ▸ Permissions. Removing it from someone who holds it through their role writes a *deny* for them. Every change is logged as `ai_access_granted` / `ai_access_revoked`, kept for the security retention window.

## Screening a job

1. **AI ▸ Recruitment AI** → open the job → optionally write **must-haves**, one per line, and pick the **office** and the **monthly salary budget** (see below).
2. **Switch on AI screening.** `recruitment:screen` (scheduler, every minute) reads the applicant list and screens applicants one by one; the page shows the progress.
3. The **Top 10** ranks screened applicants by score. Applications already rejected in Teamtailor are included and marked **Rejected**; **Leave them out** hides them (the chat tool takes `include_rejected: false` for the same).

How a score is made:

- The CV is downloaded from Teamtailor right before reading (the links are signed and expire). Teamtailor serves CVs as PDFs; text comes from `pdftotext`, and a scanned CV is read from images of its first three pages.
- One Azure OpenAI call per applicant returns the score, a summary, a check of each must-have with evidence, strengths, concerns, skills, languages, education, where the applicant lives, the estimated distance to the office and interview questions.
- The fit label comes from the score (85+ strong, 70+ good, 50+ possible). A must-have the CV clearly does **not** meet caps the score at 49.
- The instructions forbid using or mentioning name, photo, age, gender, marital status, religion, nationality or ethnicity — or inferring any of them from where someone lives — unless a must-have names one as a legal requirement of the role (for example Saudization), and they treat anything in a CV that looks like an instruction as text.

Screening again happens by itself when:

- the must-haves, the office, the salary budget or the job ad change (everyone), or `CandidateScreener::INSTRUCTIONS_VERSION` is bumped;
- an applicant uploads a new CV in Teamtailor (that person).

**Screen all again** queues everyone by hand.

## Salary and distance

Both are set beside the must-haves on the job's page, and both are optional:

- **Office**: one of the NOC's branches (its name, street and city). The AI estimates each applicant's distance by road to it.
- **Monthly salary budget**: from / to, and its currency.

Salaries are read **in code** from the application answers (`Services\Recruitment\SalaryAnswers`), never by the AI. Teamtailor has no salary field, so a question counts when it pairs *salary* with *expected*, or with *current / previous / last*, in English or Arabic. A question that only mentions salary, such as a payroll test, does not. `12,000`, `12.500`, `15k`, `10-12k`, `15 ألف` and Arabic digits are understood, and a range stays a range. A figure's currency comes from its answer, then its question, then the other figure, then the budget's. Answers to this job's own questions count first; a figure given in another application is marked as such.

The AI is then given the office, the budget, both salaries and the location on the applicant's profile:

- An expected salary above the top of the budget is a concern and lowers the score: by up to 5 points when it is less than 20% above, by up to 15 beyond. With no budget, or no expected salary, salary does not change the score, and asking for less never raises it.
- It returns where the applicant lives (area and city, never a street), the distance in km and whether they would move city. A commute over 40 km, or a move, is a concern worth up to 5 points, and none when they say they will relocate. With no office set, no distance is kept.

Distances are estimates from what applicants wrote, not a map lookup: Teamtailor holds a city for about half of applicants, and no street address or coordinates.

Where they show:

- the job's page: **Expects**, **Now** (with **Above budget**) and the distance, for every applicant;
- the candidate profile (`/admin/candidates/{id}`): the expected and current salary as numbers on top for everyone who can open it (they come from the answers shown below them), and, for people who may use Recruitment AI, the distance from the newest screening;
- the chat: `get_job_shortlist` returns them, and takes `max_expected_salary` and `max_distance_km`.

## Data

- `recruitment_jobs`: one row per job set up (must-haves, office, salary budget, switch, counters).
- `recruitment_screenings`: one row per applicant. The CV text, the application answers and `facts` (the salary they stated and where they live) are **encrypted**; also the evaluation and the score.
- Ask conversations are AI conversations linked to the job and flagged `contains_candidate_data`. **AI ▸ Conversations** shows flagged conversations only to people who may use Recruitment AI.
- Kept until someone presses **Delete AI data** on the job, which deletes the screenings and the job's Ask conversations and switches screening off. Must-haves, office and budget stay. Chat conversations in the home-portal assistant follow that assistant's own retention.
- Neither model is in the automatic audit (it would copy CV text into `activity_logs`). Switching on/off, criteria changes (must-haves, office, budget), re-screens and deletes are logged by hand.

## Operations

```sh
php artisan recruitment:screen                 # one run (240 s budget)
php artisan recruitment:screen --job=6977263   # one job only
```

- Needs the Teamtailor API key with **Admin** scope (Settings → Teamtailor) and the AI assistant configured (Settings → AI Assistant). Without either, the command waits and says why.
- Azure HTTP 429 (throttling) ends the run; the next minute continues.
- A download that fails is retried on the next two runs, then the applicant shows **Could not read** with the reason. A file that is not a PDF, is password-protected or damaged fails at once.
- Cost is one chat call per applicant screened: roughly the CV (3–10 k characters), the ad and up to 2,000 output tokens. Applicants without a CV cost no chat call.

## Not built

- Writing back to Teamtailor (tags, stages, rejections) — deliberately read-only.
- Automatic deletion after a period — data stays until deleted from the job page.
- A map lookup of addresses — Teamtailor has no street address or coordinates to look up.
