<?php

namespace App\Support;

use App\Mail\AvepointBackupReadyMail;
use App\Mail\AzureContactUpdateReminderMail;
use App\Mail\BackupDownloadReadyMail;
use App\Mail\BusinessAppAccountMail;
use App\Mail\EmployeeWelcomeMail;
use App\Mail\HrOffboardingCompleteMail;
use App\Mail\HrOffboardingManagerRequestMail;
use App\Mail\HrOnboardingCompleteMail;
use App\Mail\ItOnboardingSummaryMail;
use App\Mail\NotificationMail;
use App\Mail\OffboardingEscalationMail;
use App\Mail\OnboardingDetailsMail;
use App\Mail\OnboardingManagerFormMail;
use App\Mail\PrinterAlertMail;
use App\Mail\PrinterSetupMail;
use App\Mail\PrinterTonerDigestMail;
use App\Models\AvepointBackup;
use App\Models\Branch;
use App\Models\BusinessApp;
use App\Models\Employee;
use App\Models\MailSender;
use App\Models\NocEvent;
use App\Models\Notification;
use App\Models\OffboardingBackup;
use App\Models\OffboardingToken;
use App\Models\OffboardingWorkflow;
use App\Models\OnboardingManagerToken;
use App\Models\Printer;
use App\Models\PrinterDeployToken;
use App\Models\User;
use App\Models\WorkflowRequest;

/**
 * The catalogue of every designed email SG NOC sends.
 *
 * Like MailSender::SERVICES this lives in code, not the database — a template
 * exists because something sends it. The system_email_templates table only ever
 * holds *overrides*; delete the row and the original Blade takes over again.
 *
 * Each entry knows how to build a throwaway instance of its own Mailable, which
 * is what powers both the preview and the "load current design" seed. Nothing
 * built here is ever saved.
 */
class EmailTemplates
{
    /**
     * @return array<string, array{label:string, group:string, service:string, view:string, mailable:class-string, description:string}>
     */
    public static function all(): array
    {
        return [
            // ── Onboarding ────────────────────────────────────────────────
            'onboarding.manager_form' => [
                'label' => 'Manager setup form request',
                'group' => 'Onboarding',
                'service' => MailSender::ONBOARDING,
                'view' => 'emails.hr.onboarding_manager_request',
                'mailable' => OnboardingManagerFormMail::class,
                'description' => 'Sent to the reporting manager with the link to the new-hire setup form.',
            ],
            'onboarding.it_summary' => [
                'label' => 'IT provisioning summary',
                'group' => 'Onboarding',
                'service' => MailSender::ONBOARDING,
                'view' => 'emails.it.onboarding_summary',
                'mailable' => ItOnboardingSummaryMail::class,
                'description' => 'Internal IT copy once provisioning finishes. This is the only onboarding email that carries credentials.',
            ],
            'onboarding.details' => [
                'label' => 'New starter is ready (HR & manager)',
                'group' => 'Onboarding',
                'service' => MailSender::ONBOARDING,
                'view' => 'emails.hr.onboarding_details',
                'mailable' => OnboardingDetailsMail::class,
                'description' => 'Contact details for HR and the manager. Deliberately carries no passwords.',
            ],
            'onboarding.hr_complete' => [
                'label' => 'Onboarding complete',
                'group' => 'Onboarding',
                'service' => MailSender::ONBOARDING,
                'view' => 'emails.hr.onboarding_complete',
                'mailable' => HrOnboardingCompleteMail::class,
                'description' => 'Completion notice back to the HR requester.',
            ],
            'onboarding.employee_welcome' => [
                'label' => 'Welcome email to the new employee',
                'group' => 'Onboarding',
                'service' => MailSender::ONBOARDING,
                'view' => 'emails.employee.welcome',
                'mailable' => EmployeeWelcomeMail::class,
                'description' => 'First message the new starter receives on their own mailbox.',
            ],

            // ── Offboarding ───────────────────────────────────────────────
            'offboarding.manager_request' => [
                'label' => 'Manager decision form request',
                'group' => 'Offboarding',
                'service' => MailSender::OFFBOARDING,
                'view' => 'emails.hr.offboarding_manager_request',
                'mailable' => HrOffboardingManagerRequestMail::class,
                'description' => 'Asks the manager what to do with mail, data and assets. Also used for reminders.',
            ],
            'offboarding.hr_complete' => [
                'label' => 'Offboarding complete',
                'group' => 'Offboarding',
                'service' => MailSender::OFFBOARDING,
                'view' => 'emails.hr.offboarding_complete',
                'mailable' => HrOffboardingCompleteMail::class,
                'description' => 'Completion notice back to the HR requester.',
            ],
            'offboarding.escalation' => [
                'label' => 'Escalation to IT',
                'group' => 'Offboarding',
                'service' => MailSender::OFFBOARDING,
                'view' => 'emails.hr.offboarding_escalation',
                'mailable' => OffboardingEscalationMail::class,
                'description' => 'Raised when an offboarding stalls and needs a human in IT.',
            ],
            'offboarding.backup_ready' => [
                'label' => 'Backup download link',
                'group' => 'Offboarding',
                'service' => MailSender::OFFBOARDING,
                'view' => 'emails.hr.backup_download_ready',
                'mailable' => BackupDownloadReadyMail::class,
                'description' => 'Sent to the manager when a leaver’s mailbox or OneDrive export is ready to download.',
            ],

            // ── Accounts ──────────────────────────────────────────────────
            'business_app.account' => [
                'label' => 'Business app account request',
                'group' => 'Accounts',
                'service' => MailSender::ONBOARDING,
                'view' => 'emails.hr.business_app_account',
                'mailable' => BusinessAppAccountMail::class,
                'description' => 'Create/disable request sent to the Salesforce or Oracle account owners.',
            ],
            'employee.contact_reminder' => [
                'label' => 'Update your mobile number',
                'group' => 'Accounts',
                'service' => MailSender::NOTIFICATIONS,
                'view' => 'emails.employee.azure-contact-update-reminder',
                'mailable' => AzureContactUpdateReminderMail::class,
                'description' => 'Nudges an employee to fill in their mobile number in Outlook.',
            ],

            // ── Printers ──────────────────────────────────────────────────
            'printers.setup' => [
                'label' => 'Printer setup instructions',
                'group' => 'Printers',
                'service' => MailSender::PRINTERS,
                'view' => 'emails.printer_setup',
                'mailable' => PrinterSetupMail::class,
                'description' => 'Branch printer install link sent to a user.',
            ],
            'printers.alert' => [
                'label' => 'Printer alert',
                'group' => 'Printers',
                'service' => MailSender::PRINTERS,
                'view' => 'emails.printer-alert',
                'mailable' => PrinterAlertMail::class,
                'description' => 'Single printer incident — offline, jam, empty supply.',
            ],
            'printers.toner_digest' => [
                'label' => 'Toner digest',
                'group' => 'Printers',
                'service' => MailSender::PRINTERS,
                'view' => 'emails.printer-toner-digest',
                'mailable' => PrinterTonerDigestMail::class,
                'description' => 'Scheduled roll-up of low supplies across all branches.',
            ],

            // ── Platform ──────────────────────────────────────────────────
            'backups.avepoint_ready' => [
                'label' => 'AvePoint backup ready',
                'group' => 'Platform',
                'service' => MailSender::BACKUPS,
                'view' => 'emails.avepoint.backup_ready',
                'mailable' => AvepointBackupReadyMail::class,
                'description' => 'A requested AvePoint export has finished and can be collected.',
            ],
            'system.notification' => [
                'label' => 'General notification',
                'group' => 'Platform',
                'service' => MailSender::NOTIFICATIONS,
                'view' => 'emails.notification',
                'mailable' => NotificationMail::class,
                'description' => 'The wrapper every in-app notification uses when it is also emailed. Editing this affects a lot of mail. The From address varies by notification type — alerts go out as the NOC & Monitoring sender, not this one.',
            ],
        ];
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /**
     * @return array{label:string, group:string, service:string, view:string, mailable:class-string, description:string}|null
     */
    public static function meta(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** Catalogue grouped for the index page, catalogue order preserved. */
    public static function grouped(): array
    {
        $out = [];
        foreach (self::all() as $key => $meta) {
            $out[$meta['group']][$key] = $meta;
        }

        return $out;
    }

    /** Absolute path of a template's Blade file, for reading its field markers. */
    public static function viewPath(string $key): ?string
    {
        $meta = self::meta($key);
        if (! $meta) {
            return null;
        }

        $path = resource_path('views/'.str_replace('.', '/', $meta['view']).'.blade.php');

        return is_file($path) ? $path : null;
    }

    /**
     * A throwaway Mailable filled with obviously-fake data, used for the preview
     * pane, the seed body and the test send. Never persisted.
     */
    public static function sampleMailable(string $key): \Illuminate\Mail\Mailable
    {
        return match ($key) {
            'onboarding.manager_form' => new OnboardingManagerFormMail(
                self::sampleWorkflow('onboarding'),
                self::sampleOnboardingToken(),
            ),
            'onboarding.it_summary' => new ItOnboardingSummaryMail(
                self::sampleWorkflow('onboarding'), 'it@samirgroup.com',
            ),
            'onboarding.details' => new OnboardingDetailsMail(
                self::sampleWorkflow('onboarding'), 'manager',
            ),
            'onboarding.hr_complete' => new HrOnboardingCompleteMail(
                self::sampleWorkflow('onboarding'), 'hr@samirgroup.com',
            ),
            'onboarding.employee_welcome' => new EmployeeWelcomeMail(
                self::sampleWorkflow('onboarding'),
            ),
            'offboarding.manager_request' => new HrOffboardingManagerRequestMail(
                self::sampleWorkflow('offboarding'), self::sampleOffboardingToken(), false,
            ),
            'offboarding.hr_complete' => new HrOffboardingCompleteMail(
                self::sampleWorkflow('offboarding'), 'hr@samirgroup.com',
            ),
            'offboarding.escalation' => new OffboardingEscalationMail(
                self::sampleOffboardingWorkflow(),
            ),
            'offboarding.backup_ready' => new BackupDownloadReadyMail(
                self::sampleOffboardingBackup(),
            ),
            'business_app.account' => new BusinessAppAccountMail(
                self::sampleEmployee(), self::sampleBusinessApp(), BusinessAppAccountMail::ACTIVATE,
            ),
            'employee.contact_reminder' => new AzureContactUpdateReminderMail(
                self::sampleEmployee(),
            ),
            'printers.setup' => new PrinterSetupMail(
                self::samplePrinterDeployToken(),
                collect([self::samplePrinter()]),
                url('/printer-setup/preview'),
            ),
            'printers.alert' => new PrinterAlertMail(
                self::sampleNocEvent(), self::samplePrinter(),
            ),
            'printers.toner_digest' => new PrinterTonerDigestMail(
                [[
                    'branch' => 'Jeddah',
                    'rows' => [[
                        'printer' => 'JED-Reception',
                        'location' => 'Ground floor',
                        'color' => 'Black',
                        'percent' => 8,
                    ]],
                ]],
                1,
                'this week',
                '[SG NOC] Toner digest — 1 printer needs attention',
            ),
            'backups.avepoint_ready' => new AvepointBackupReadyMail(
                self::sampleAvepointBackup(),
            ),
            'system.notification' => new NotificationMail(
                self::sampleNotification(), self::sampleUser(),
            ),
            default => throw new \InvalidArgumentException("No sample data for template [{$key}]."),
        };
    }

    // ─────────────────────────────────────────────────────────────────
    // Sample records — unsaved, deliberately obvious placeholder data
    // ─────────────────────────────────────────────────────────────────

    private static function sampleWorkflow(string $type): WorkflowRequest
    {
        $w = new WorkflowRequest([
            'type' => $type,
            'status' => 'completed',
            'payload' => [
                'display_name' => 'Sara Al-Rashid',
                'first_name' => 'Sara',
                'last_name' => 'Al-Rashid',
                'upn' => 'sara.alrashid@samirgroup.com',
                'email' => 'sara.alrashid@samirgroup.com',
                'job_title' => 'Accountant',
                'department' => 'Finance',
                'branch' => 'Jeddah',
                'manager_name' => 'Preview Manager',
                'manager_email' => 'manager@samirgroup.com',
                'extension' => '2145',
                'mobile' => '+966 5X XXX XXXX',
                'start_date' => now()->addDays(7)->toDateString(),
                'last_day' => now()->addDays(14)->toDateString(),
                'reason' => 'Resignation',
                'hr_reference' => 'PREVIEW-0000',
                'initial_password' => '••••••••',
                'licenses' => [
                    ['sku' => 'SPB', 'name' => 'Microsoft 365 Business Premium'],
                    ['sku' => 'VISIOCLIENT', 'name' => 'Visio Plan 2'],
                ],
                'groups' => ['Finance – All', 'Jeddah – Staff'],
            ],
        ]);
        $w->id = 0;
        $w->created_at = now();
        $w->updated_at = now();

        $branch = new Branch(['name' => 'Jeddah']);
        $branch->id = 0;
        $w->setRelation('branch', $branch);

        return $w;
    }

    private static function sampleOnboardingToken(): OnboardingManagerToken
    {
        $t = new OnboardingManagerToken([
            'token' => 'preview',
            'manager_email' => 'manager@samirgroup.com',
            'manager_name' => 'Preview Manager',
            'expires_at' => now()->addDays(14),
        ]);
        $t->id = 0;

        return $t;
    }

    private static function sampleOffboardingToken(): OffboardingToken
    {
        $t = new OffboardingToken([
            'token' => 'preview',
            'manager_email' => 'manager@samirgroup.com',
            'manager_name' => 'Preview Manager',
            'expires_at' => now()->addDays(7),
            'payload' => [
                'display_name' => 'Sara Al-Rashid',
                'upn' => 'sara.alrashid@samirgroup.com',
                'job_title' => 'Accountant',
                'last_day' => now()->addDays(14)->toDateString(),
                'reason' => 'Resignation',
                'hr_reference' => 'PREVIEW-0000',
            ],
        ]);
        $t->id = 0;
        $t->workflow_id = 0;

        return $t;
    }

    private static function sampleEmployee(): Employee
    {
        $e = new Employee([
            'name' => 'Sara Al-Rashid',
            'email' => 'sara.alrashid@samirgroup.com',
            'job_title' => 'Accountant',
            'extension_number' => '2145',
            'status' => 'active',
        ]);
        $e->id = 0;

        return $e;
    }

    private static function sampleOffboardingWorkflow(): OffboardingWorkflow
    {
        $ow = new OffboardingWorkflow([
            'status' => 'processing',
            'expected_last_day' => now()->addDays(14)->toDateString(),
        ]);
        $ow->id = 0;
        $ow->setRelation('employee', self::sampleEmployee());
        $ow->setRelation('workflow', self::sampleWorkflow('offboarding'));

        return $ow;
    }

    private static function sampleOffboardingBackup(): OffboardingBackup
    {
        $b = new OffboardingBackup([
            'type' => 'mailbox',
            'status' => 'ready',
            'file_size' => 4_509_715_660,
        ]);
        $b->id = 0;
        $b->setRelation('offboardingWorkflow', self::sampleOffboardingWorkflow());

        return $b;
    }

    private static function sampleAvepointBackup(): AvepointBackup
    {
        $b = new AvepointBackup([
            'subject_upn' => 'sara.alrashid@samirgroup.com',
            'subject_name' => 'Sara Al-Rashid',
            'type' => 'mailbox',
            'status' => 'ready',
            'file_size' => 4_509_715_660,
        ]);
        $b->id = 0;

        return $b;
    }

    private static function sampleBusinessApp(): BusinessApp
    {
        $a = new BusinessApp([
            'key' => 'salesforce',
            'name' => 'Salesforce',
            'is_active' => true,
        ]);
        $a->id = 0;

        return $a;
    }

    private static function samplePrinter(): Printer
    {
        $p = new Printer([
            'printer_name' => 'JED-Reception',
            'model' => 'Ricoh MP C3003',
            'ip_address' => '10.1.0.51',
        ]);
        $p->id = 0;

        $branch = new Branch(['name' => 'Jeddah']);
        $branch->id = 0;
        $p->setRelation('branch', $branch);

        return $p;
    }

    private static function samplePrinterDeployToken(): PrinterDeployToken
    {
        $t = new PrinterDeployToken([
            'token' => 'preview',
            'sent_to_email' => 'sara.alrashid@samirgroup.com',
            'expires_at' => now()->addDays(7),
        ]);
        $t->id = 0;

        $branch = new Branch(['name' => 'Jeddah']);
        $branch->id = 0;
        $t->setRelation('branch', $branch);

        return $t;
    }

    private static function sampleNocEvent(): NocEvent
    {
        $e = new NocEvent([
            'title' => 'Printer offline',
            'severity' => 'warning',
            'message' => 'JED-Reception has not answered SNMP for 15 minutes.',
        ]);
        $e->id = 0;
        $e->created_at = now();

        return $e;
    }

    private static function sampleNotification(): Notification
    {
        $n = new Notification([
            'title' => 'Sample notification',
            'message' => 'This is what a notification email looks like.',
            'type' => 'info',
        ]);
        $n->id = 0;
        $n->created_at = now();

        return $n;
    }

    private static function sampleUser(): User
    {
        $u = new User([
            'name' => 'Preview Recipient',
            'email' => 'preview@samirgroup.com',
        ]);
        $u->id = 0;

        return $u;
    }
}
