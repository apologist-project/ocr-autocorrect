<?php

namespace OcrAutoCorrect;

use Illuminate\Support\ServiceProvider;

/**
 * Class PasswordServiceProvider
 */
class OcrAutoCorrectServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton('OcrAutoCorrect', OcrAutoCorrect::class);
    }

}