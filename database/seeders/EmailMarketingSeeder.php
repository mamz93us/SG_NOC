<?php

namespace Database\Seeders;

use App\Models\EmailMarketing\EmailCampaign;
use App\Models\EmailMarketing\EmailList;
use App\Models\EmailMarketing\EmailSubscriber;
use App\Models\EmailMarketing\EmailTemplate;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class EmailMarketingSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $owner = User::first();

        $list = EmailList::firstOrCreate(
            ['name' => 'Customers'],
            [
                'description'     => 'Demo customers list',
                'double_opt_in'   => false,
                'created_by'      => $owner?->id,
            ]
        );

        // 10 demo subscribers
        $samples = [
            ['ahmed.demo@example.com',    'Ahmed',    'Saleh'],
            ['sara.demo@example.com',     'Sara',     'Hassan'],
            ['mohamed.demo@example.com',  'Mohamed',  'Khalid'],
            ['leila.demo@example.com',    'Leila',    'Mansour'],
            ['omar.demo@example.com',     'Omar',     'Farouk'],
            ['fatima.demo@example.com',   'Fatima',   'Hussein'],
            ['khalid.demo@example.com',   'Khalid',   'Yusuf'],
            ['noor.demo@example.com',     'Noor',     'Al-Rashid'],
            ['yasmin.demo@example.com',   'Yasmin',   'Tariq'],
            ['hassan.demo@example.com',   'Hassan',   'Najjar'],
        ];

        foreach ($samples as [$email, $first, $last]) {
            $sub = EmailSubscriber::firstOrCreate(
                ['email' => $email],
                [
                    'first_name'   => $first,
                    'last_name'    => $last,
                    'status'       => 'subscribed',
                    'source'       => 'seeder',
                    'confirmed_at' => now(),
                ]
            );
            $list->subscribers()->syncWithoutDetaching([
                $sub->id => ['subscribed_at' => now()],
            ]);
        }

        $template = EmailTemplate::firstOrCreate(
            ['name' => 'Welcome (demo)'],
            [
                'preview_text'  => 'Welcome to our newsletter',
                'design_json'   => json_encode(['body' => ['rows' => []]]),
                'rendered_html' => <<<HTML
<!doctype html><html><body style="font-family: sans-serif; padding: 24px;">
<h2>Hello {{first_name}}!</h2>
<p>Thanks for subscribing to our newsletter. We'll be in touch shortly.</p>
<p style="color: #666; font-size: 12px;">Don't want these emails? <a href="{{unsubscribe_url}}">Unsubscribe</a>.</p>
</body></html>
HTML,
                'created_by' => $owner?->id,
            ]
        );

        EmailCampaign::firstOrCreate(
            ['name' => 'Welcome blast (demo)'],
            [
                'subject'           => 'Welcome to our newsletter, {{first_name}}!',
                'preview_text'      => 'Welcome to the family.',
                'from_email'        => 'noreply@samirgroup.com',
                'from_name'         => 'Samir Group',
                'email_template_id' => $template->id,
                'email_list_id'     => $list->id,
                'status'            => 'draft',
                'created_by'        => $owner?->id,
            ]
        );
    }
}
