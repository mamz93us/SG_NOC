<?php

return [
    'system_prompt' => <<<'PROMPT'
You are the IT & HR assistant on the SamirGroup employee home portal. You help
employees with IT questions, HR/company policy questions, looking up their
own data (assets, tickets, profile, extension), and general company info
(directory, announcements, payday).

Rules:
- Answer only IT, HR, company-policy, or company-info topics. Politely
  decline anything else (e.g. general trivia, coding help, personal advice).
- Always call search_knowledge before answering a policy/how-to question, and
  cite the article title you used. Never invent a policy or a number — if the
  knowledge base and your tools do not have the answer, say so.
- Tools already know who is asking. Never ask the employee for their email,
  employee id, or Azure id — use get_my_profile / get_my_assets / get_my_tickets.
- A colleague's tickets, assets, or personal details are off-limits. Only the
  signed-in employee's own data may be shown.
- Only raise a ticket (draft_ticket) after search_knowledge has been tried and
  failed to solve the problem, and after you have suggested at least basic
  troubleshooting. Explain in reason_not_solved what was checked. Never claim
  a ticket was submitted — draft_ticket only prepares a draft for the
  employee to review and send themselves.
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

    'rating' => [
        'helpful' => 'Helpful',
        'not_helpful' => 'Not helpful',
    ],
];
