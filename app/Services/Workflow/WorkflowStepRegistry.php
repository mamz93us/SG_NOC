<?php

namespace App\Services\Workflow;

/**
 * Registry of Laravel Jobs available as "action" nodes in the visual workflow builder.
 * Jobs are organised into groups for display in the builder UI.
 * Add new jobs here to make them selectable in the builder.
 */
class WorkflowStepRegistry
{
    /**
     * Returns jobs organised by group label.
     * Used by the visual builder UI (grouped <optgroup> select).
     *
     * @return array<string, array<int, array{label: string, class: string, params: array}>>
     */
    public static function grouped(): array
    {
        return [

            // ── Azure / Microsoft 365 ──────────────────────────────
            'Azure / Microsoft 365' => [
                [
                    'label'  => 'Create Azure User',
                    'class'  => \App\Jobs\Azure\CreateAzureUserJob::class,
                    'params' => [
                        ['key' => 'first_name',    'label' => 'First Name',      'type' => 'text'],
                        ['key' => 'last_name',     'label' => 'Last Name',       'type' => 'text'],
                        ['key' => 'upn',           'label' => 'UPN / Email',     'type' => 'text', 'hint' => 'e.g. {{payload.email}}'],
                        ['key' => 'email_domain',  'label' => 'Email Domain',    'type' => 'text', 'placeholder' => 'company.com'],
                        ['key' => 'job_title',     'label' => 'Job Title',       'type' => 'text'],
                        ['key' => 'department',    'label' => 'Department',      'type' => 'text'],
                        ['key' => 'usage_location','label' => 'Usage Location',  'type' => 'text', 'placeholder' => 'SA'],
                    ],
                ],
                [
                    'label'  => 'Disable Azure User',
                    'class'  => \App\Jobs\Azure\DisableAzureUserJob::class,
                    'params' => [
                        ['key' => 'user_id', 'label' => 'Azure Object ID', 'type' => 'text', 'hint' => 'e.g. {{payload.azure_id}}'],
                        ['key' => 'upn',     'label' => 'UPN / Email',     'type' => 'text'],
                    ],
                ],
                [
                    'label'  => 'Enable Azure User',
                    'class'  => \App\Jobs\Azure\EnableAzureUserJob::class,
                    'params' => [
                        ['key' => 'user_id', 'label' => 'Azure Object ID', 'type' => 'text'],
                        ['key' => 'upn',     'label' => 'UPN / Email',     'type' => 'text'],
                    ],
                ],
                [
                    'label'  => 'Reset Azure Password',
                    'class'  => \App\Jobs\Azure\ResetAzurePasswordJob::class,
                    'params' => [
                        ['key' => 'user_id',              'label' => 'Azure Object ID',          'type' => 'text'],
                        ['key' => 'upn',                  'label' => 'UPN / Email',              'type' => 'text'],
                        ['key' => 'new_password',         'label' => 'New Password',             'type' => 'text', 'placeholder' => 'Leave blank to auto-generate'],
                        ['key' => 'force_change_on_login','label' => 'Force Change on Next Login','type' => 'select',
                            'options' => [['value' => 'true', 'label' => 'Yes'], ['value' => 'false', 'label' => 'No']]],
                    ],
                ],
                [
                    'label'  => 'Assign Microsoft 365 License',
                    'class'  => \App\Jobs\Azure\AssignAzureLicenseJob::class,
                    'params' => [
                        ['key' => 'user_id',       'label' => 'Azure Object ID', 'type' => 'text'],
                        ['key' => 'upn',           'label' => 'UPN / Email',     'type' => 'text'],
                        ['key' => 'license_sku_id','label' => 'License SKU ID',  'type' => 'text', 'hint' => 'e.g. SPE_E3 or the GUID'],
                    ],
                ],
                [
                    'label'  => 'Remove Microsoft 365 License',
                    'class'  => \App\Jobs\Azure\RemoveAzureLicenseJob::class,
                    'params' => [
                        ['key' => 'user_id',       'label' => 'Azure Object ID', 'type' => 'text'],
                        ['key' => 'upn',           'label' => 'UPN / Email',     'type' => 'text'],
                        ['key' => 'license_sku_id','label' => 'License SKU ID',  'type' => 'text'],
                    ],
                ],
                [
                    'label'  => 'Assign Azure AD Group',
                    'class'  => \App\Jobs\Azure\AssignAzureGroupJob::class,
                    'params' => [
                        ['key' => 'user_id',    'label' => 'Azure Object ID', 'type' => 'text'],
                        ['key' => 'upn',        'label' => 'UPN / Email',     'type' => 'text'],
                        ['key' => 'group_id',   'label' => 'Group Object ID', 'type' => 'text'],
                        ['key' => 'group_name', 'label' => 'Group Name',      'type' => 'text', 'hint' => 'Display name for logs'],
                    ],
                ],
                [
                    'label'  => 'Remove from Azure AD Group',
                    'class'  => \App\Jobs\Azure\RemoveAzureGroupJob::class,
                    'params' => [
                        ['key' => 'user_id',    'label' => 'Azure Object ID', 'type' => 'text'],
                        ['key' => 'upn',        'label' => 'UPN / Email',     'type' => 'text'],
                        ['key' => 'group_id',   'label' => 'Group Object ID', 'type' => 'text'],
                        ['key' => 'group_name', 'label' => 'Group Name',      'type' => 'text'],
                    ],
                ],
                [
                    'label'  => 'Update Azure User Profile',
                    'class'  => \App\Jobs\Azure\UpdateAzureProfileJob::class,
                    'params' => [
                        ['key' => 'user_id',         'label' => 'Azure Object ID',  'type' => 'text'],
                        ['key' => 'upn',             'label' => 'UPN / Email',      'type' => 'text'],
                        ['key' => 'job_title',       'label' => 'Job Title',        'type' => 'text'],
                        ['key' => 'department',      'label' => 'Department',       'type' => 'text'],
                        ['key' => 'office_location', 'label' => 'Office Location',  'type' => 'text'],
                        ['key' => 'mobile_phone',    'label' => 'Mobile Phone',     'type' => 'text'],
                        ['key' => 'manager_id',      'label' => 'Manager Object ID','type' => 'text'],
                    ],
                ],
                [
                    'label'  => 'Convert to Shared Mailbox',
                    'class'  => \App\Jobs\Azure\ConvertToSharedMailboxJob::class,
                    'params' => [
                        ['key' => 'user_id',            'label' => 'Azure Object ID',     'type' => 'text'],
                        ['key' => 'upn',                'label' => 'UPN / Email',         'type' => 'text'],
                        ['key' => 'shared_mailbox_name','label' => 'Shared Mailbox Name', 'type' => 'text'],
                        ['key' => 'delegate_access_to', 'label' => 'Delegate Access To',  'type' => 'text', 'hint' => 'UPN or group to grant full access'],
                    ],
                ],
                [
                    'label'  => 'Delete Azure User',
                    'class'  => \App\Jobs\Azure\DeleteAzureUserJob::class,
                    'params' => [
                        ['key' => 'user_id', 'label' => 'Azure Object ID', 'type' => 'text'],
                        ['key' => 'upn',     'label' => 'UPN / Email',     'type' => 'text'],
                    ],
                ],
                [
                    'label'  => 'Assign Microsoft Teams Phone',
                    'class'  => \App\Jobs\Azure\AssignTeamsPhoneJob::class,
                    'params' => [
                        ['key' => 'user_id',     'label' => 'Azure Object ID',   'type' => 'text'],
                        ['key' => 'upn',         'label' => 'UPN / Email',       'type' => 'text'],
                        ['key' => 'phone_number','label' => 'Phone Number (E.164)','type' => 'text', 'placeholder' => '+966XXXXXXXXX'],
                        ['key' => 'policy_name', 'label' => 'Teams Voice Policy', 'type' => 'text'],
                    ],
                ],
                [
                    'label'  => 'Create SharePoint Folder',
                    'class'  => \App\Jobs\Azure\CreateSharePointFolderJob::class,
                    'params' => [
                        ['key' => 'site_url',     'label' => 'SharePoint Site URL', 'type' => 'text'],
                        ['key' => 'library_name', 'label' => 'Document Library',   'type' => 'text'],
                        ['key' => 'folder_path',  'label' => 'Folder Path',         'type' => 'text', 'placeholder' => 'HR/Onboarding/2026'],
                    ],
                ],
            ],

            // ── UCM Phone System ───────────────────────────────────
            'UCM Phone System' => [
                [
                    'label'  => 'Create UCM Extension',
                    'class'  => \App\Jobs\Ucm\CreateUcmExtensionJob::class,
                    'params' => [
                        ['key' => 'extension',          'label' => 'Extension Number', 'type' => 'text'],
                        ['key' => 'first_name',         'label' => 'First Name',       'type' => 'text'],
                        ['key' => 'last_name',          'label' => 'Last Name',        'type' => 'text'],
                        ['key' => 'department',         'label' => 'Department',       'type' => 'text'],
                        ['key' => 'voicemail_enabled',  'label' => 'Enable Voicemail', 'type' => 'select',
                            'options' => [['value' => '1', 'label' => 'Yes'], ['value' => '0', 'label' => 'No']]],
                        ['key' => 'ring_timeout',       'label' => 'Ring Timeout (sec)','type' => 'text', 'placeholder' => '30'],
                    ],
                ],
                [
                    'label'  => 'Delete UCM Extension',
                    'class'  => \App\Jobs\Ucm\DeleteUcmExtensionJob::class,
                    'params' => [
                        ['key' => 'extension', 'label' => 'Extension Number', 'type' => 'text'],
                    ],
                ],
                [
                    'label'  => 'Update UCM Extension',
                    'class'  => \App\Jobs\Ucm\UpdateUcmExtensionJob::class,
                    'params' => [
                        ['key' => 'extension',         'label' => 'Extension Number', 'type' => 'text'],
                        ['key' => 'first_name',        'label' => 'First Name',       'type' => 'text'],
                        ['key' => 'last_name',         'label' => 'Last Name',        'type' => 'text'],
                        ['key' => 'department',        'label' => 'Department',       'type' => 'text'],
                        ['key' => 'voicemail_enabled', 'label' => 'Voicemail',        'type' => 'select',
                            'options' => [['value' => '1', 'label' => 'Enabled'], ['value' => '0', 'label' => 'Disabled']]],
                    ],
                ],
                [
                    'label'  => 'Enable UCM Extension',
                    'class'  => \App\Jobs\Ucm\EnableUcmExtensionJob::class,
                    'params' => [
                        ['key' => 'extension', 'label' => 'Extension Number', 'type' => 'text'],
                    ],
                ],
                [
                    'label'  => 'Disable UCM Extension',
                    'class'  => \App\Jobs\Ucm\DisableUcmExtensionJob::class,
                    'params' => [
                        ['key' => 'extension', 'label' => 'Extension Number', 'type' => 'text'],
                    ],
                ],
                [
                    'label'  => 'Assign Extension to User',
                    'class'  => \App\Jobs\Ucm\AssignUcmExtensionJob::class,
                    'params' => [
                        ['key' => 'extension',    'label' => 'Extension Number', 'type' => 'text'],
                        ['key' => 'user_email',   'label' => 'User Email',       'type' => 'text'],
                        ['key' => 'mac_address',  'label' => 'Phone MAC Address','type' => 'text', 'placeholder' => 'AA:BB:CC:DD:EE:FF'],
                        ['key' => 'phone_model',  'label' => 'Phone Model',      'type' => 'text', 'placeholder' => 'GRP2614'],
                    ],
                ],
                [
                    'label'  => 'Create UCM Call Queue',
                    'class'  => \App\Jobs\Ucm\CreateUcmCallQueueJob::class,
                    'params' => [
                        ['key' => 'queue_name',   'label' => 'Queue Name',        'type' => 'text'],
                        ['key' => 'extension',    'label' => 'Queue Extension',   'type' => 'text'],
                        ['key' => 'strategy',     'label' => 'Ring Strategy',     'type' => 'select',
                            'options' => [
                                ['value' => 'ringall',   'label' => 'Ring All'],
                                ['value' => 'leastrecent','label' => 'Least Recent'],
                                ['value' => 'roundrobin','label' => 'Round Robin'],
                            ]],
                        ['key' => 'timeout',      'label' => 'Queue Timeout (sec)', 'type' => 'text', 'placeholder' => '60'],
                        ['key' => 'members',      'label' => 'Member Extensions',   'type' => 'text', 'hint' => 'Comma-separated: 1001,1002,1003'],
                    ],
                ],
            ],

            // ── Active Directory ───────────────────────────────────
            'Active Directory' => [
                [
                    'label'  => 'Create AD User Account',
                    'class'  => \App\Jobs\AD\CreateAdUserJob::class,
                    'params' => [
                        ['key' => 'username',         'label' => 'Username (sAMAccountName)', 'type' => 'text'],
                        ['key' => 'first_name',       'label' => 'First Name',               'type' => 'text'],
                        ['key' => 'last_name',        'label' => 'Last Name',                'type' => 'text'],
                        ['key' => 'email',            'label' => 'Email Address',            'type' => 'text'],
                        ['key' => 'ou_path',          'label' => 'Organisational Unit (OU)', 'type' => 'text', 'placeholder' => 'OU=Users,DC=company,DC=com'],
                        ['key' => 'initial_password', 'label' => 'Initial Password',         'type' => 'text', 'hint' => 'Leave blank to auto-generate'],
                        ['key' => 'department',       'label' => 'Department',               'type' => 'text'],
                    ],
                ],
                [
                    'label'  => 'Disable AD Account',
                    'class'  => \App\Jobs\AD\DisableAdAccountJob::class,
                    'params' => [
                        ['key' => 'username', 'label' => 'Username', 'type' => 'text'],
                        ['key' => 'ou_path',  'label' => 'OU Path',  'type' => 'text'],
                    ],
                ],
                [
                    'label'  => 'Enable AD Account',
                    'class'  => \App\Jobs\AD\EnableAdAccountJob::class,
                    'params' => [
                        ['key' => 'username', 'label' => 'Username', 'type' => 'text'],
                    ],
                ],
                [
                    'label'  => 'Move AD User to OU',
                    'class'  => \App\Jobs\AD\MoveAdOuJob::class,
                    'params' => [
                        ['key' => 'username',   'label' => 'Username',       'type' => 'text'],
                        ['key' => 'source_ou',  'label' => 'Source OU',      'type' => 'text'],
                        ['key' => 'target_ou',  'label' => 'Target OU Path', 'type' => 'text', 'placeholder' => 'OU=Leavers,DC=company,DC=com'],
                    ],
                ],
                [
                    'label'  => 'Add AD User to Group',
                    'class'  => \App\Jobs\AD\AddToAdGroupJob::class,
                    'params' => [
                        ['key' => 'username',   'label' => 'Username',   'type' => 'text'],
                        ['key' => 'group_name', 'label' => 'Group Name', 'type' => 'text'],
                    ],
                ],
                [
                    'label'  => 'Remove AD User from Group',
                    'class'  => \App\Jobs\AD\RemoveFromAdGroupJob::class,
                    'params' => [
                        ['key' => 'username',   'label' => 'Username',   'type' => 'text'],
                        ['key' => 'group_name', 'label' => 'Group Name', 'type' => 'text'],
                    ],
                ],
                [
                    'label'  => 'Reset AD Password',
                    'class'  => \App\Jobs\AD\ResetAdPasswordJob::class,
                    'params' => [
                        ['key' => 'username',             'label' => 'Username',                  'type' => 'text'],
                        ['key' => 'new_password',         'label' => 'New Password',              'type' => 'text', 'placeholder' => 'Leave blank to auto-generate'],
                        ['key' => 'force_change_on_login','label' => 'Force Change on Next Login','type' => 'select',
                            'options' => [['value' => 'true', 'label' => 'Yes'], ['value' => 'false', 'label' => 'No']]],
                    ],
                ],
            ],

            // ── Notifications & Messaging ──────────────────────────
            'Notifications & Messaging' => [
                [
                    'label'  => 'Send Email Notification',
                    'class'  => \App\Jobs\SendWorkflowEmailJob::class,
                    'params' => [
                        ['key' => 'to',      'label' => 'Recipient',    'type' => 'text', 'hint' => 'Email address or role:it_manager'],
                        ['key' => 'subject', 'label' => 'Subject',      'type' => 'text'],
                        ['key' => 'body',    'label' => 'Body',         'type' => 'textarea'],
                    ],
                ],
                [
                    'label'  => 'Webhook / HTTP Request',
                    'class'  => \App\Jobs\SendWorkflowWebhookJob::class,
                    'params' => [
                        ['key' => 'url',          'label' => 'URL',          'type' => 'text'],
                        ['key' => 'method',       'label' => 'HTTP Method',  'type' => 'select',
                            'options' => [['value' => 'POST', 'label' => 'POST'], ['value' => 'PUT', 'label' => 'PUT'], ['value' => 'PATCH', 'label' => 'PATCH']]],
                        ['key' => 'payload_json', 'label' => 'Payload (JSON)', 'type' => 'textarea', 'placeholder' => '{"key": "{{payload.value}}"}'],
                    ],
                ],
                [
                    'label'  => 'Send Slack Message',
                    'class'  => \App\Jobs\Notifications\SendSlackMessageJob::class,
                    'params' => [
                        ['key' => 'webhook_url', 'label' => 'Slack Webhook URL', 'type' => 'text'],
                        ['key' => 'channel',     'label' => 'Channel',           'type' => 'text', 'placeholder' => '#it-alerts'],
                        ['key' => 'message',     'label' => 'Message',           'type' => 'textarea'],
                        ['key' => 'username',    'label' => 'Bot Username',       'type' => 'text', 'placeholder' => 'NOC Bot'],
                    ],
                ],
                [
                    'label'  => 'Send Teams Adaptive Card',
                    'class'  => \App\Jobs\Notifications\SendTeamsCardJob::class,
                    'params' => [
                        ['key' => 'webhook_url', 'label' => 'Teams Incoming Webhook URL', 'type' => 'text'],
                        ['key' => 'title',       'label' => 'Card Title',                 'type' => 'text'],
                        ['key' => 'message',     'label' => 'Card Body',                  'type' => 'textarea'],
                        ['key' => 'color',       'label' => 'Accent Color (hex)',          'type' => 'text', 'placeholder' => 'FF0000'],
                    ],
                ],
                [
                    'label'  => 'Send SMS via Twilio',
                    'class'  => \App\Jobs\Notifications\SendSmsJob::class,
                    'params' => [
                        ['key' => 'to',      'label' => 'Recipient Phone (E.164)', 'type' => 'text', 'placeholder' => '+966XXXXXXXXX'],
                        ['key' => 'message', 'label' => 'Message',                 'type' => 'textarea'],
                    ],
                ],
            ],

            // ── ITSM / Ticketing ───────────────────────────────────
            'ITSM / Ticketing' => [
                [
                    'label'  => 'Create ServiceNow Incident',
                    'class'  => \App\Jobs\Itsm\CreateServiceNowIncidentJob::class,
                    'params' => [
                        ['key' => 'category',         'label' => 'Category',         'type' => 'text'],
                        ['key' => 'short_description','label' => 'Short Description','type' => 'text'],
                        ['key' => 'description',      'label' => 'Description',      'type' => 'textarea'],
                        ['key' => 'assignment_group', 'label' => 'Assignment Group', 'type' => 'text'],
                        ['key' => 'priority',         'label' => 'Priority',         'type' => 'select',
                            'options' => [
                                ['value' => '1', 'label' => '1 – Critical'],
                                ['value' => '2', 'label' => '2 – High'],
                                ['value' => '3', 'label' => '3 – Medium'],
                                ['value' => '4', 'label' => '4 – Low'],
                            ]],
                    ],
                ],
                [
                    'label'  => 'Update Jira Issue',
                    'class'  => \App\Jobs\Itsm\UpdateJiraIssueJob::class,
                    'params' => [
                        ['key' => 'issue_key', 'label' => 'Jira Issue Key', 'type' => 'text', 'placeholder' => 'IT-1234'],
                        ['key' => 'status',    'label' => 'Transition To',  'type' => 'text', 'placeholder' => 'In Progress'],
                        ['key' => 'assignee',  'label' => 'Assignee',       'type' => 'text'],
                        ['key' => 'comment',   'label' => 'Comment',        'type' => 'textarea'],
                    ],
                ],
                [
                    'label'  => 'Create Jira Ticket',
                    'class'  => \App\Jobs\Itsm\CreateJiraTicketJob::class,
                    'params' => [
                        ['key' => 'project_key',  'label' => 'Project Key',  'type' => 'text', 'placeholder' => 'IT'],
                        ['key' => 'issue_type',   'label' => 'Issue Type',   'type' => 'select',
                            'options' => [['value' => 'Task', 'label' => 'Task'], ['value' => 'Bug', 'label' => 'Bug'], ['value' => 'Story', 'label' => 'Story']]],
                        ['key' => 'summary',      'label' => 'Summary',      'type' => 'text'],
                        ['key' => 'description',  'label' => 'Description',  'type' => 'textarea'],
                        ['key' => 'assignee',     'label' => 'Assignee Email','type' => 'text'],
                        ['key' => 'priority',     'label' => 'Priority',     'type' => 'select',
                            'options' => [['value' => 'High', 'label' => 'High'], ['value' => 'Medium', 'label' => 'Medium'], ['value' => 'Low', 'label' => 'Low']]],
                    ],
                ],
            ],

            // ── System / Infrastructure ────────────────────────────
            'System / Infrastructure' => [
                [
                    'label'  => 'Run Remote SSH Command',
                    'class'  => \App\Jobs\Infra\RunSshCommandJob::class,
                    'params' => [
                        ['key' => 'host',     'label' => 'Host / IP',   'type' => 'text'],
                        ['key' => 'username', 'label' => 'SSH Username', 'type' => 'text'],
                        ['key' => 'command',  'label' => 'Command',      'type' => 'textarea', 'hint' => 'Command runs as the specified user'],
                    ],
                ],
                [
                    'label'  => 'Execute Ansible Playbook',
                    'class'  => \App\Jobs\Infra\RunAnsiblePlaybookJob::class,
                    'params' => [
                        ['key' => 'playbook',   'label' => 'Playbook Path',     'type' => 'text', 'placeholder' => '/opt/playbooks/onboard.yml'],
                        ['key' => 'inventory',  'label' => 'Inventory',         'type' => 'text', 'placeholder' => 'production'],
                        ['key' => 'extra_vars', 'label' => 'Extra Vars (JSON)', 'type' => 'textarea', 'placeholder' => '{"user": "{{payload.username}}"}'],
                    ],
                ],
                [
                    'label'  => 'Restart Windows Service',
                    'class'  => \App\Jobs\Infra\RestartWindowsServiceJob::class,
                    'params' => [
                        ['key' => 'host',         'label' => 'Host / IP',       'type' => 'text'],
                        ['key' => 'service_name', 'label' => 'Service Name',    'type' => 'text', 'placeholder' => 'Spooler'],
                    ],
                ],
                [
                    'label'  => 'Create DNS Record',
                    'class'  => \App\Jobs\Infra\CreateDnsRecordJob::class,
                    'params' => [
                        ['key' => 'zone',     'label' => 'DNS Zone',    'type' => 'text', 'placeholder' => 'company.com'],
                        ['key' => 'name',     'label' => 'Record Name', 'type' => 'text', 'placeholder' => 'hostname'],
                        ['key' => 'type',     'label' => 'Record Type', 'type' => 'select',
                            'options' => [['value' => 'A', 'label' => 'A'], ['value' => 'CNAME', 'label' => 'CNAME'], ['value' => 'MX', 'label' => 'MX']]],
                        ['key' => 'value',    'label' => 'Record Value','type' => 'text'],
                        ['key' => 'ttl',      'label' => 'TTL (seconds)','type' => 'text', 'placeholder' => '300'],
                    ],
                ],
                [
                    'label'  => 'Update DHCP Reservation',
                    'class'  => \App\Jobs\Infra\UpdateDhcpReservationJob::class,
                    'params' => [
                        ['key' => 'mac_address', 'label' => 'MAC Address',      'type' => 'text'],
                        ['key' => 'ip_address',  'label' => 'Reserved IP',      'type' => 'text'],
                        ['key' => 'hostname',    'label' => 'Hostname',          'type' => 'text'],
                        ['key' => 'scope_id',    'label' => 'DHCP Scope ID',     'type' => 'text'],
                    ],
                ],
            ],

            // ── HR / Onboarding ────────────────────────────────────
            'HR / Onboarding' => [
                [
                    'label'  => 'Assign Asset to Employee',
                    'class'  => \App\Jobs\Hr\AssignAssetJob::class,
                    'params' => [
                        ['key' => 'employee_id',  'label' => 'Employee ID',   'type' => 'text'],
                        ['key' => 'asset_tag',    'label' => 'Asset Tag',     'type' => 'text'],
                        ['key' => 'asset_type',   'label' => 'Asset Type',    'type' => 'select',
                            'options' => [
                                ['value' => 'laptop',  'label' => 'Laptop'],
                                ['value' => 'desktop', 'label' => 'Desktop'],
                                ['value' => 'phone',   'label' => 'Phone'],
                                ['value' => 'other',   'label' => 'Other'],
                            ]],
                        ['key' => 'notes',        'label' => 'Notes',         'type' => 'textarea'],
                    ],
                ],
                [
                    'label'  => 'Revoke All Access (Offboarding)',
                    'class'  => \App\Jobs\Hr\RevokeAllAccessJob::class,
                    'params' => [
                        ['key' => 'employee_id', 'label' => 'Employee ID', 'type' => 'text'],
                        ['key' => 'user_email',  'label' => 'User Email',  'type' => 'text'],
                        ['key' => 'azure_id',    'label' => 'Azure ID',    'type' => 'text'],
                        ['key' => 'ad_username', 'label' => 'AD Username', 'type' => 'text'],
                    ],
                ],
                [
                    'label'  => 'Send Welcome Email',
                    'class'  => \App\Jobs\Hr\SendWelcomeEmailJob::class,
                    'params' => [
                        ['key' => 'to',         'label' => 'Recipient Email', 'type' => 'text'],
                        ['key' => 'first_name', 'label' => 'First Name',     'type' => 'text'],
                        ['key' => 'start_date', 'label' => 'Start Date',     'type' => 'text'],
                        ['key' => 'manager',    'label' => 'Manager Name',   'type' => 'text'],
                        ['key' => 'department', 'label' => 'Department',     'type' => 'text'],
                    ],
                ],
                [
                    'label'  => 'Schedule IT Equipment Delivery',
                    'class'  => \App\Jobs\Hr\ScheduleEquipmentDeliveryJob::class,
                    'params' => [
                        ['key' => 'employee_name',  'label' => 'Employee Name',    'type' => 'text'],
                        ['key' => 'delivery_date',  'label' => 'Delivery Date',    'type' => 'text', 'placeholder' => '{{payload.start_date}}'],
                        ['key' => 'location',       'label' => 'Office Location',  'type' => 'text'],
                        ['key' => 'equipment_list', 'label' => 'Equipment List',   'type' => 'textarea'],
                    ],
                ],
            ],

        ];
    }

    /**
     * Flat list of all registered jobs (used by WorkflowEngine for lookups).
     *
     * @return array<int, array{label: string, class: string, params: array}>
     */
    public static function available(): array
    {
        $flat = [];
        foreach (static::grouped() as $jobs) {
            foreach ($jobs as $job) {
                $flat[] = $job;
            }
        }
        return $flat;
    }

    /**
     * Return human-readable label for a job class.
     */
    public static function labelFor(string $class): string
    {
        foreach (static::available() as $item) {
            if ($item['class'] === $class) {
                return $item['label'];
            }
        }
        return class_basename($class);
    }

    /**
     * Resolve template variables like {{payload.first_name}} from workflow payload.
     */
    public static function resolveParams(array $params, array $payload): array
    {
        array_walk_recursive($params, function (&$value) use ($payload) {
            if (is_string($value)) {
                $value = preg_replace_callback('/\{\{payload\.(\w+)\}\}/', function ($m) use ($payload) {
                    return $payload[$m[1]] ?? '';
                }, $value);
            }
        });
        return $params;
    }
}
