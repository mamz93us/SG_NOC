<?php

namespace App\Mail\Concerns;

use App\Models\SystemEmailTemplate;
use App\Support\EmailTemplateRenderer;
use App\Support\EmailTemplates;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Support\Facades\Log;

/**
 * Lets a Mailable be overridden from Admin → Email Templates.
 *
 * The Mailable keeps its Blade as the source of truth for *data*: the view is
 * always rendered, because that is where the field markers get filled in with
 * real values. What an override changes is the *layout* — the stored HTML is
 * then assembled from those already-rendered chunks.
 *
 * Every failure path lands on the original design. A broken override degrades
 * the look of an email; it never stops one being sent.
 */
trait UsesEmailTemplate
{
    /** @var array<string, string>|null */
    private ?array $renderedChunks = null;

    /** Catalogue key from App\Support\EmailTemplates. */
    abstract public function templateKey(): string;

    /** Extra view data the Mailable would otherwise pass via Content(with:). */
    protected function templateWith(): array
    {
        return [];
    }

    /**
     * Body for this mail: the override if one is saved and usable, otherwise
     * the original view with its markers stripped.
     */
    protected function templatedContent(): Content
    {
        $key = $this->templateKey();
        $view = EmailTemplates::meta($key)['view'] ?? null;

        if (! $view) {
            // Key not in the catalogue — nothing to override against.
            return new Content(view: $view ?? '', with: $this->templateWith());
        }

        $default = $this->renderDefault($view);

        $custom = SystemEmailTemplate::bodyFor($key);
        if ($custom === null) {
            return new Content(htmlString: EmailTemplateRenderer::strip($default));
        }

        try {
            return new Content(htmlString: EmailTemplateRenderer::fill($custom, $this->renderedChunks ?? []));
        } catch (\Throwable $e) {
            Log::error("[EmailTemplate] Override for [{$key}] failed to render, falling back: ".$e->getMessage());

            return new Content(htmlString: EmailTemplateRenderer::strip($default));
        }
    }

    /**
     * Subject for this mail. Falls back to the string the Mailable already
     * built, so an override is opt-in per template.
     */
    protected function templatedSubject(string $default): string
    {
        $key = $this->templateKey();
        $custom = SystemEmailTemplate::subjectFor($key);

        if ($custom === null) {
            return $default;
        }

        try {
            // envelope() runs before content(), so the chunks usually are not
            // cached yet — render once to get them, then reuse for the body.
            $view = EmailTemplates::meta($key)['view'] ?? null;
            $chunks = $this->renderedChunks ?? ($view ? EmailTemplateRenderer::extract($this->renderDefault($view)) : []);

            $filled = EmailTemplateRenderer::fillText($custom, $chunks);

            return $filled !== '' ? $filled : $default;
        } catch (\Throwable $e) {
            Log::error("[EmailTemplate] Subject override for [{$key}] failed, falling back: ".$e->getMessage());

            return $default;
        }
    }

    /**
     * The coded design, markers intact — what the admin editor renders against
     * to build the seed body, the placeholder list and the preview.
     */
    public function renderCodedView(): string
    {
        $view = EmailTemplates::meta($this->templateKey())['view'] ?? null;

        if (! $view) {
            return '';
        }

        return view($view, array_merge($this->buildViewData(), $this->templateWith()))->render();
    }

    /** The subject this Mailable would use with no override in play. */
    public function codedSubject(): string
    {
        // envelope() routes through templatedSubject(), so stand the overrides
        // down for the duration to see what the code alone would produce.
        return SystemEmailTemplate::withoutOverrides(fn () => (string) ($this->envelope()->subject ?? ''));
    }

    /**
     * Render the coded view once per Mailable instance and remember both the
     * HTML and the field chunks pulled out of it.
     */
    private function renderDefault(string $view): string
    {
        $html = view($view, array_merge($this->buildViewData(), $this->templateWith()))->render();

        $this->renderedChunks = EmailTemplateRenderer::extract($html);

        return $html;
    }
}
