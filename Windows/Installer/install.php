<?php
date_default_timezone_set('UTC');
register_shutdown_function('on_exit');
log_('Installer v1.0 started.'.PHP_EOL);
title('Loading...');
unlink('installed');
touch('closed');

$setup = array(
	'unreal' => array(
		'iso' => 'https://archive.org/download/gt-unreal-1998/Unreal.iso',
		'iso_size' => 477050880,
		'patch_fallback' => 'https://api.github.com/repos/OldUnreal/Unreal-testing/releases/tags/v227k',
		'patch' => 'https://api.github.com/repos/OldUnreal/Unreal-testing/releases/latest',
	),
	'ugold' => array(
		'iso' => 'https://archive.org/download/totallyunreal/UNREAL_GOLD.ISO',
		'iso_size' => 676734976,
		'patch_fallback' => 'https://api.github.com/repos/OldUnreal/Unreal-testing/releases/tags/v227k',
		'patch' => 'https://api.github.com/repos/OldUnreal/Unreal-testing/releases/latest',
	),
	'ut99' => array(
		'iso' => 'https://archive.org/download/ut-goty/UT_GOTY_CD1.iso',
		'iso_size' => 649633792,
		'patch' => 'https://api.github.com/repos/OldUnreal/UnrealTournamentPatches/releases/latest',
	),
);
$game = isset($argv[1]) ? $argv[1] : 'ut99';

$config = $setup[$game];

title('Downloading game ISO...');

get_file($config['iso'], $config['iso_size']);

$win_ver = php_uname('r');
log_('Detected Windows version: '.$win_ver);
$win_vista = '6.0';
$win_xp = $game == 'ut99' && floatval($win_ver) < floatval($win_vista);
log_('Compare with Vista version ('.$win_vista.'): '.($win_xp ? 'Use WindowsXP build' : 'Use build for modern Windows (Vista or above)'));

title('Downloading patch releases list...');

$file = false;
$tries = isset($config['patch_fallback']) ? 2 : 1;
for ($try = 1; $try <= $tries; $try++) {
	if ($try == 2) {
		if ($file && file_exists($file)) unlink($file);
		$config['patch'] = $config['patch_fallback'];
		unset($config['patch_fallback']);
	}
	log_('Try obtain releases list from '.$config['patch']);
	$file = basename($config['patch']);

	get_file($config['patch'], -1, $try == $tries);

	if (!file_exists($file)) {
		if ($try != $tries) continue;
		end_('Failed get releases list from '.$config['patch']);
	}
	$releases = file_get_contents($file);
	$list = json_decode($releases, true);
	if (!$list) {
		if ($try != $tries) continue;
		end_('Failed decode as JSON:'.PHP_EOL.'--- start ---'.PHP_EOL.$releases.PHP_EOL.'--- end ---');
	}
	if (!isset($list['assets'])) {
		if ($try != $tries) continue;
		json_error($releases, 'assets not found');
	}
	if (empty($list['assets'])) {
		if ($try != $tries) continue;
		json_error($releases, 'assets empty');
	}
	$patch = false;
	foreach ($list['assets'] as $asset) {
		if (strpos($asset['name'], '-Windows') && strpos($asset['name'], '.zip') && strpos($asset['name'], '-WindowsXP') == $win_xp) {
			$patch = $asset;
		}
	}
	if (!$patch) {
		if ($try != $tries) continue;
		json_error($releases, 'no matching asset');
	}
}
log_('Use '.basename($patch['browser_download_url']).' for patch.');

title('Downloading patch ZIP...');

get_file($patch['browser_download_url'], $patch['size']);

title('Unpacking game ISO...');

run('tools\7z x -aoa -o.. -x@skip.txt '.basename($config['iso']));

title('Unpacking patch ZIP...');

run('tools\7z x -aoa -o.. '.basename($patch['browser_download_url']));

$progress = 'Unpacking game files... ';
title($progress);

$uzs = glob_recursive('../*.uz');
$done = 0;
$cnt = count($uzs);
foreach ($uzs as $uz) {
	title($progress.round(100.0*$done++/$cnt, 1).'%');
	log_('Unpack '.$uz);
	$dir = dirname($uz);
	$file = basename($uz, '.uz');
	if (file_exists($dir.'/'.$file)) {
		log_('Already unpacked. Remove uz file.');
		unlink($uz);
		continue;
	}
	run('..\System\ucc decompress '.$uz);
	if (realpath($dir) != realpath('../System')) {
		if (file_exists('../System/'.$file)) {
			rename('../System/'.$file, $dir.'/'.$file);
		}
	}
	if (file_exists($dir.'/'.$file)) {
		log_('Unpacked. Remove uz file.');
		unlink($uz);
	}
}
unset($uzs);

title('Alter game configuration...');

if ($game == 'ut99') {
	copy('UnrealTournament.ini', '../System/UnrealTournament.ini');
	copy('User.ini', '../System/User.ini');
}

title('Remove downloaded files...');
unlink(basename($config['patch']));
unlink(basename($config['iso']));
unlink(basename($patch['browser_download_url']));

title('Game installed');
log_('Game installed'.str_repeat(PHP_EOL, 20));

touch('installed');
end_('Game installed sucessfully.'.PHP_EOL, 0);

function glob_recursive($pattern, $flags = 0) {
	$files = glob($pattern, $flags);
	foreach (glob(dirname($pattern).'/*', GLOB_ONLYDIR|GLOB_NOSORT) as $dir) {
		$files = array_merge($files, glob_recursive($dir.'/'.basename($pattern), $flags));
	}
	return $files;
}

function on_exit() {
	log_('Installer exit.'.PHP_EOL);
	unlink('closed');
}

function title($title) {
	log_($title);
	shell_exec('title '.$title);
}

function run($cmd) {
	log_('Execute: '.$cmd);
	$result = 42;
	passthru($cmd, $result);

	log_('Result: '.$result);
	return $result;
}

function get_file($url, $expected_size, $die = true) {
	$file = basename($url);
	if (file_exists($file)) {
		if ($expected_size < 0) {
			log_('Force download requested. Remove old file and try download it again.');
			unlink($file);
		} else {
			$filesize = filesize($file);
			log_('Found '.$file.' of size '.human_size($filesize));
			if ($filesize == $expected_size) {
				log_('Size match to expected size. Use that file.');
			} else {
				log_('Size not match to expected size ('.human_size($expected_size).'). Remove file and try download it again.');
				unlink($file);
			}
		}
	}

	if (!file_exists($file)) {
		download($url, $expected_size, $die);
	}
}

function json_error($json, $error) {
	end_('Unexpected JSON data ('.$error.'):'.PHP_EOL.'--- start ---'.PHP_EOL.$json.PHP_EOL.'--- end ---');
}

function download($url, $expected_size, $die = true) {
	$result_file = basename($url);
	log_('Start download '.$result_file.' from '.$url);

	$result = run('tools\wget '.$url);

	if ($result != 0) {
		if (!$die) return;
		end_('Failed download '.$result_file.' from '.$url.'. Abort.');
	}

	if (!file_exists($result_file)) {
		if (!$die) return;
		end_('File '.$result_file.' not found. Abort.');
	}

	if ($expected_size < 0) return;

	$filesize = filesize($result_file);
	if ($filesize != $expected_size) {
		log_('File size of '.$result_file.' is '.human_size($filesize).
			', which not match expect size '.human_size($expected_size));
		if ($filesize < 16) {
			if (!$die) return;
			end_('File size of '.$result_file.' is too small. Abort.');
		}
	}
}

function human_size($filesize) {
	return number_format($filesize, 0, '', ' ');
}

function log_($line) {
	$line = date('Y-m-d H:i:s> ').$line.PHP_EOL;
	echo $line;
	file_put_contents('install.log', $line, FILE_APPEND);
}

function end_($reason, $code = 1) {
	log_($reason.PHP_EOL);
//	log_('Press Enter to close this window.');
//	fgetc(STDIN);
	die($code);
}