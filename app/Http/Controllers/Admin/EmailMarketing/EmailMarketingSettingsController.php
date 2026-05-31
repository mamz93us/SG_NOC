<?php

namespace App\Http\Controllers\Admin\EmailMarketing;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmailMarketing\UpdateEmailMarketingSettingsRequest;
use App\Models\Setting;
use App\Services\EmailMarketing\EmailMarketingNotConfiguredException;
use App\Services\EmailMarketing\SesService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\View\View;

class EmailMarketingSettingsController extends Controller
{
    public function index(): View
    {
        $settings = Setting::get();

        return view('admin.email-marketing.settings', [
            'settings' => $settings,
            'regions' => $this->commonSesRegions(),
        ]);
    }

    public function update(UpdateEmailMarketingSettingsRequest $request): \Illuminate\Http\RedirectResponse
    {
        $settings = Setting::get();
        $data = $request->validated();

        // Treat empty secret_access_key as "no change" — admin shouldn't have to
        // re-enter it every time they tweak something else.
        if (empty($data['ses_secret_access_key']) && $settings->ses_secret_access_key) {
            unset($data['ses_secret_access_key']);
        }

        $domainChanged = array_key_exists('marketing_domain', $data)
            && $data['marketing_domain'] !== $settings->marketing_domain;

        $settings->fill($data)->save();

        // The marketing subdomain is baked into the route table (Route::domain).
        // Clear the route cache so a new host takes effect on the next request,
        // even when routes were cached at deploy time.
        if ($domainChanged) {
            Artisan::call('route:clear');
        }

        return back()->with('status', 'Email marketing settings saved.');
    }

    public function testSend(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'to' => ['required', 'email', 'max:191'],
        ]);

        try {
            $ses = app(SesService::class);
            $messageId = $ses->sendRawTestEmail(
                $validated['to'],
                '[SG NOC] Email Marketing test',
                '<p>This is a test email from SG NOC. If you received it, your AWS SES integration is working.</p>'
            );

            return back()->with('status', "Test email sent — SES MessageId: {$messageId}");
        } catch (EmailMarketingNotConfiguredException $e) {
            return back()->withErrors(['ses' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return back()->withErrors(['ses' => 'AWS error: '.$e->getMessage()]);
        }
    }

    private function commonSesRegions(): array
    {
        return [
            'us-east-1' => 'US East (N. Virginia)',
            'us-east-2' => 'US East (Ohio)',
            'us-west-2' => 'US West (Oregon)',
            'eu-west-1' => 'EU (Ireland)',
            'eu-west-2' => 'EU (London)',
            'eu-central-1' => 'EU (Frankfurt)',
            'eu-north-1' => 'EU (Stockholm)',
            'eu-south-1' => 'EU (Milan)',
            'me-south-1' => 'Middle East (Bahrain)',
            'me-central-1' => 'Middle East (UAE)',
            'ap-south-1' => 'Asia Pacific (Mumbai)',
            'ap-southeast-1' => 'Asia Pacific (Singapore)',
            'ap-southeast-2' => 'Asia Pacific (Sydney)',
            'ap-northeast-1' => 'Asia Pacific (Tokyo)',
            'ca-central-1' => 'Canada (Central)',
        ];
    }
}
