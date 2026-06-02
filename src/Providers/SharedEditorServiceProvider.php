<?php

declare(strict_types=1);

namespace Maya\Editor\Providers;

use Illuminate\Support\ServiceProvider;
use Maya\Editor\Renderers\TiptapHtmlRenderer;

final class SharedEditorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TiptapHtmlRenderer::class);
    }

    public function boot(): void
    {
    }
}
