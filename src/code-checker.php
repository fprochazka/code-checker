<?php

/**
 * Source Codes Checker.
 *
 * Copyright (c) 2010 David Grudl (http://davidgrudl.com)
 *
 * This source file is subject to the "Nette license", and/or
 * GPL license. For more information please see http://nette.org
 */

require __DIR__ . '/loader.php';

use Nette\String;


echo '
CodeChecker version 0.9
-----------------------
';

$options = getopt('d:fl');

if (!$options) { ?>
Usage: php code-checker.php [options]

Options:
	-d <path>  folder to scan (optional)
	-f         fixes files
	-l         convert newline characters

<?php
}



class CodeChecker extends Nette\Object
{
	public $tasks = array();

	public $readOnly = FALSE;

	public $accept = array(
		'*.php', '*.phpc', '*.phpt', '*.inc',
		'*.txt', '*.texy',
		'*.css', '*.js', '*.htm', '*.html', '*.phtml', '*.xml',
		'*.ini', '*.config',
		'*.sh',	'*.bat',
		'.htaccess', '.gitignore',
	);

	public $ignore = array(
		'.*', '*.tmp', 'tmp', 'temp', 'log',
	);

	private $file;

	private $error;


	public function run($folder)
	{
		set_time_limit(0);

		if ($this->readOnly) {
			echo "Running in read-only mode\n";
		}

		echo "Scanning folder $folder...\n";

		$counter = 0;
		foreach (Nette\Finder::findFiles($this->accept)->from($folder)->exclude($this->ignore) as $file)
		{
			echo str_pad(str_repeat('.', $counter++ % 40), 40), "\x0D";

			$orig = $s = file_get_contents($file);
			$this->file = ltrim(str_replace($folder, '', $file), '/\\');
			$this->error = FALSE;

			foreach ($this->tasks as $task) {
				$res = $task($this, $s);
				if ($this->error) {
					continue 2;
				} elseif (is_string($res)) {
					$s = $res;
				}
			}

			if ($s !== $orig && !$this->readOnly) {
				file_put_contents($file, $s);
			}
		}

		echo "\nDone.";
	}


	public function fix($message)
	{
		echo '[' . ($this->readOnly ? 'FOUND' : 'FIX') . "] $this->file   $message\n";
	}


	public function warning($message)
	{
		echo "[WARNING] $this->file   $message\n";
	}


	public function error($message)
	{
		echo "[ERROR] $this->file   $message\n";
		$this->error = TRUE;
	}


	public function is($extension)
	{
		return $extension === pathinfo($this->file, PATHINFO_EXTENSION);
	}

}



$checker = new CodeChecker;
$checker->readOnly = !isset($options['f']);

// control characters checker
$checker->tasks[] = function($checker, $s) {
	if (String::match($s, '#[\x00-\x08\x0B\x0C\x0E-\x1F]#')) {
		$checker->error('contains control characters');
	}
};

// BOM remover
$checker->tasks[] = function($checker, $s) {
    if (substr($s, 0, 3) === "\xEF\xBB\xBF") {
    	$checker->fix('contains BOM');
    	return substr($s, 3);
    }
};

// UTF-8 checker
$checker->tasks[] = function($checker, $s) {
	if (!String::checkEncoding($s)) {
		$checker->error('in not valid UTF-8 file');
	}
};

// invalid phpDoc checker
$checker->tasks[] = function($checker, $s) {
    if ($checker->is('php')) {
    	foreach (token_get_all($s) as $token) {
    		if ($token[0] === T_COMMENT && String::match($token[1], '#/\*\s.*@[a-z]#isA')) {
    			$checker->warning("missing /** in phpDoc comment on line $token[2]");
    		}
    	}
    }
};

// newline characters normalizer for the current OS
if (isset($options['l'])) {
	$checker->tasks[] = function($checker, $s) {
		$new = str_replace("\n", PHP_EOL, str_replace("\r\n", "\n", $s));
		if ($new !== $s) {
    		$checker->fix('contains non-system line-endings');
    		return $new;
		}
	};
}

// trailing ? > remover
$checker->tasks[] = function($checker, $s) {
    if ($checker->is('php')) {
		$tmp = rtrim($s);
		if (substr($tmp, -2) === '?>') {
    		$checker->fix('contains closing PHP tag ?>');
			return substr($tmp, 0, -2);
		}
    }
};

// lint Latte templates
$checker->tasks[] = function($checker, $s) {
    if ($checker->is('phtml')) {
    	try {
			$template = new Nette\Templates\FileTemplate;
			$template->registerFilter(new Nette\Templates\LatteFilter);
			$template->compile($s);
		} catch (Nette\Templates\TemplateException $e) {
    		$checker->error($e->getMessage() . ($e->sourceLine ? " on line $e->sourceLine" : ''));
		}
    }
};

// white-space remover
$checker->tasks[] = function($checker, $s) {
    $new = String::replace($s, "#[\t ]+(\r?\n)#", '$1'); // right trim
    $new = String::replace($new, "#(\r?\n)+$#", '$1'); // trailing trim
    if ($new !== $s) {
    	$bytes = strlen($s) - strlen($new);
   		$checker->fix("$bytes bytes of whitespaces");
   		return $new;
   	}
};

$checker->run(isset($options['d']) ? $options['d'] : getcwd());
