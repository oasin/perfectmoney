<?php 
namespace Oasin\PerfectMoney\Facades;  

use Illuminate\Support\Facades\Facade;  

use Oasin\PerfectMoney\PerfectMoney as PerfectMoneyClass;

class PerfectMoney extends Facade 
{
	protected static function getFacadeAccessor() { 
		return PerfectMoneyClass::class;   
	}
}
