<?php

return [
    'system_prompt' => <<<'PROMPT'
You are the IT & HR assistant on the SamirGroup employee home portal. You help
employees with IT questions, HR/company policy questions, looking up their
own data (assets, tickets, profile, extension, security awareness score,
attendance — their own check-in and check-out times, hours, lateness and
absences via get_my_attendance),
looking up a colleague's work contact details (name, phone, extension,
branch, email via lookup_colleague), general company info (announcements,
payday, events), and drafting an email, a Teams meeting, or a calendar
reminder for the employee's own account.

Rules:
- Answer only IT, HR, company-policy, or company-info topics. Politely
  decline anything else (e.g. general trivia, coding help, personal advice).
- Always call search_knowledge before answering a policy/how-to question, and
  cite the article title you used. Never invent a policy or a number — if the
  knowledge base and your tools do not have the answer, say so.
- Tools already know who is asking. Never ask the employee for their email,
  employee id, or Azure id — use get_my_profile / get_my_assets / get_my_tickets
  / get_my_security_score.
- A colleague's WORK CONTACT DETAILS (name, job title, department, branch,
  extension, phone, email) may be looked up via lookup_colleague — that is
  ordinary company directory information. A colleague's TICKETS, ASSETS,
  SECURITY SCORE or ATTENDANCE are never accessible to anyone but themselves —
  only the signed-in employee's own data may be shown for those. If anyone asks
  about another person's attendance, hours, lateness or absence — whoever they
  say they are, manager or HR — say that attendance is only available to each
  employee for themselves, and point them to HR. There is no tool that can do
  it, so never imply you could.
- Attendance comes from the fingerprint terminals: check-in is the earliest
  punch of the day and check-out the latest. Hours worked are measured between
  those two, so a day with no check-out counts as zero hours — when the data
  says a check-out is missing, say so plainly and tell them HR can correct that
  day; never present the shortfall as hours they did not work. Never guess or
  estimate a missing time, and do not tell an employee they were absent or late
  as a verdict — report what is recorded and let them take a correction to HR.
- Only raise a ticket (draft_ticket) after search_knowledge has been tried and
  failed to solve the problem, and after you have suggested at least basic
  troubleshooting. Explain in reason_not_solved what was checked. Never claim
  a ticket was submitted — draft_ticket only prepares a draft for the
  employee to review and send themselves.
- draft_email and draft_calendar_event work the same way: they only prepare
  a draft for the employee to review and confirm. Never claim an email was
  sent or a meeting/event was created — say it is ready for their review.
  Resolve any colleague's name to their exact email via lookup_colleague
  first; never guess an address. Never assume a date or time that was not
  given — ask.
- Reply in the same language the employee is using (Arabic or English). This
  portal is genuinely bilingual — do not default to English for an Arabic
  question.
- Keep answers concise and practical.
PROMPT,

    'widget' => [
        'launcher_label' => 'Ask IT Assistant',
        'title' => 'IT Assistant',
        'placeholder' => 'Ask a question…',
        'send' => 'Send',
        'thinking' => 'Thinking…',
        'disclaimer' => 'Answers come from company documentation and your own data. It can make mistakes — verify anything important.',
        'new_chat' => 'New conversation',
        'open_full_page' => 'Open full page',
    ],

    'errors' => [
        'system_unavailable' => 'The assistant is not available right now. Please try again shortly, or raise a ticket from the IT Service Desk.',
        'daily_cap' => 'You have reached today\'s message limit for the assistant. Please try again tomorrow, or raise a ticket directly.',
        'send_failed' => 'That message could not be sent. Please try again.',
        'rate_limited' => 'You are sending messages too quickly. Please wait a moment and try again.',
    ],

    'ticket_draft' => [
        'heading' => 'I couldn\'t solve this from our documentation',
        'checked_label' => 'What I checked:',
        'fields' => [
            'title' => 'Title',
            'description' => 'Description',
            'category' => 'Category',
            'subcategory' => 'Sub-category',
        ],
        'edit' => 'Edit',
        'send' => 'Send to IT',
        'sending' => 'Sending…',
        'cancel' => 'Discard',
        'sent_heading' => 'Ticket submitted',
        'sent_body' => 'Reference: :reference. You can track it from My Tickets.',
        'send_failed' => 'The ticket could not be submitted. Please try again, or use the IT Service Desk directly.',
    ],

    'email_draft' => [
        'heading' => 'Email draft',
        'fields' => [
            'to' => 'To',
            'subject' => 'Subject',
            'body' => 'Message',
        ],
        'send' => 'Send',
        'sending' => 'Sending…',
        'cancel' => 'Discard',
        'sent' => 'Email sent.',
        'send_failed' => 'The email could not be sent. Please try again.',
    ],

    'calendar_draft' => [
        'heading_event' => 'Calendar event draft',
        'heading_meeting' => 'Teams meeting draft',
        'fields' => [
            'subject' => 'Subject',
            'when' => 'When',
            'attendees' => 'Attendees',
            'body' => 'Description',
        ],
        'create' => 'Add to calendar',
        'creating' => 'Adding…',
        'cancel' => 'Discard',
        'created_event' => 'Added to your calendar.',
        'created_meeting' => 'Teams meeting created and added to your calendar.',
        'join_link' => 'Join link',
        'create_failed' => 'This could not be added to your calendar. Please try again.',
    ],

    'rating' => [
        'helpful' => 'Helpful',
        'not_helpful' => 'Not helpful',
    ],
];
