<?php

namespace ArtSkills\Error;

use ArtSkills\Lib\Env;
use Cake\Log\Log;

/**
 * Класс доп. проверки входных значений публичных методов. В тестовом режиме код падает с ошибкой, в релизном - пошет в лог
 */
class Assert extends \Webmozart\Assert\Assert
{
	
}