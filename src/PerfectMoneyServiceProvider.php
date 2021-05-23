<?php

namespace oasin\PerfectMoney;

use Illuminate\Support\ServiceProvider;

class PerfectMoneyServiceProvider extends ServiceProvider
{
    /**
     * Perform post-registration booting of services.
     *
     * @return void
     */
    public function boot()
    {
	
		// Config
		$this->publishes([
      
			__DIR__ . '/../src/config/perfectmoney.php' => config_path('perfectmoney.php'),
		], 'config');
		
		
    }

    /**
     * Register any package services.
     *
     * @return void
     */
    public function register()
    {
        //
    }
}