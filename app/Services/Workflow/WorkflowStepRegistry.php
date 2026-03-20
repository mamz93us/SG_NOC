<?php

namespace App\Services\Workflow;

/**
 * Registry of Laravel Jobs available as "action" nodes in the visual workflow builder.
 * Add new jobs here to make them selectable in the builder UI.
 */
class WorkflowStepRegistry
{
    public static function available(): array
    {
        return [
            [
                'label'  => 'Create Azure User',
                'class'  => \App\Jobs\ExecuteWorkflowJob::class, // Uses existing provisioning via workflow payload
                'params' => ['first_name', 'last_name', 'email_domain', 'job_title', 'department'],
            ],
            [
                'label'  => 'Send Email Notification',
                'class'  => \App\Jobs\SendWorkflowEmailJob::class,
                'params' => ['to', 'subject', 'body'],
            ],
            [
                'label'  => 'Webhook / HTTP Request',
                'class'  => \App\Jobs\SendWorkflowWebhookJob::class,
                'params' => ['url', 'method', 'payload_json'],
            ],
        ];
    }

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
