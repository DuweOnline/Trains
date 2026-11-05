<?php
/**
 * Shared National Rail (OpenLDBWS) helpers for the departure boards.
 *
 * The access token is never stored in this folder. It is read from the
 * NRE_TOKEN environment variable (or Apache SetEnv), or from nre-config.php
 * in the folder above the web root (e.g. /home/<user>/nre-config.php when
 * this site lives in /home/<user>/public_html/trains). That file looks like:
 *
 *     <?php return ['token' => 'your-token-here'];
 */

date_default_timezone_set('Europe/London');

const NRE_URL       = 'https://lite.realtime.nationalrail.co.uk/OpenLDBWS/ldb11.asmx';
const NRE_ROWS      = 10;
const NRE_CACHE_TTL = 30; // seconds a successful response is reused for

/** Escape a value for HTML output. */
function h(string $value): string {
	return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function nre_token(): ?string {
	$token = $_SERVER['NRE_TOKEN'] ?? getenv('NRE_TOKEN');
	if ($token) {
		return $token;
	}

	// Above the web root, found via DOCUMENT_ROOT or via this file's location (…/public_html/trains/lib)
	$candidates = [dirname(__DIR__, 3) . '/nre-config.php'];
	$docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
	if ($docRoot !== '') {
		array_unshift($candidates, dirname($docRoot) . '/nre-config.php');
	}

	foreach ($candidates as $path) {
		if (is_readable($path)) {
			$config = require $path;
			if (!empty($config['token'])) {
				return (string) $config['token'];
			}
		}
	}

	error_log('NRE token not configured: set NRE_TOKEN or create nre-config.php above the web root');
	return null;
}

function nre_request_body(string $token, string $crs, ?string $filterCrs): string {
	$x = fn(string $s) => htmlspecialchars($s, ENT_XML1, 'UTF-8');
	$filter = $filterCrs === null ? '' :
		'<ldb:filterCrs>' . $x($filterCrs) . '</ldb:filterCrs><ldb:filterType>to</ldb:filterType>';

	return '<?xml version="1.0" encoding="utf-8"?>'
		. '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope" xmlns:typ="http://thalesgroup.com/RTTI/2013-11-28/Token/types" xmlns:ldb="http://thalesgroup.com/RTTI/2017-10-01/ldb/">'
		. '<soap:Header><typ:AccessToken><typ:TokenValue>' . $x($token) . '</typ:TokenValue></typ:AccessToken></soap:Header>'
		. '<soap:Body><ldb:GetDepartureBoardRequest>'
		. '<ldb:numRows>' . NRE_ROWS . '</ldb:numRows>'
		. '<ldb:crs>' . $x($crs) . '</ldb:crs>' . $filter
		. '</ldb:GetDepartureBoardRequest></soap:Body></soap:Envelope>';
}

/**
 * Path of a board's cache file, or null when caching isn't possible here.
 * Uses a private (0700) folder so other users on the server can't plant or
 * read cached responses; @ keeps open_basedir/permission warnings out of the page.
 */
function nre_cache_path(string $crs, ?string $filterCrs): ?string {
	$dir = sys_get_temp_dir() . '/nre-cache-' . md5(__DIR__);
	if (!@is_dir($dir) && !@mkdir($dir, 0700)) {
		return null;
	}
	if (function_exists('posix_geteuid') && @fileowner($dir) !== posix_geteuid()) {
		return null;
	}
	if (!@is_writable($dir)) {
		return null;
	}
	$key = preg_replace('/\W/', '', $crs . '-' . ($filterCrs ?? 'ALL'));
	return "$dir/$key.xml";
}

function nre_cache_read(string $crs, ?string $filterCrs): ?string {
	$path = nre_cache_path($crs, $filterCrs);
	if ($path !== null && is_file($path) && filemtime($path) > time() - NRE_CACHE_TTL) {
		$xml = file_get_contents($path);
		return $xml === false ? null : $xml;
	}
	return null;
}

function nre_cache_write(string $crs, ?string $filterCrs, string $xml): void {
	$path = nre_cache_path($crs, $filterCrs);
	if ($path === null) {
		return;
	}
	$tmp = $path . '.' . getmypid();
	// Write then rename so a concurrent reader never sees a half-written file
	if (@file_put_contents($tmp, $xml) !== false && !@rename($tmp, $path)) {
		@unlink($tmp);
	}
}

/**
 * Parse a SOAP response and return its GetStationBoardResult, or null if the
 * response is malformed or is a SOAP fault.
 */
function nre_parse(string $xml, string $label): ?SimpleXMLElement {
	// Strip namespace prefixes only — safe, does not touch tag names or attributes
	$clean = preg_replace('/<(\/?)\w+:/', '<$1', $xml);

	$previous = libxml_use_internal_errors(true);
	$doc = simplexml_load_string($clean);
	libxml_clear_errors();
	libxml_use_internal_errors($previous);

	if ($doc === false) {
		error_log("NRE XML parse error ($label)");
		return null;
	}
	if (!isset($doc->Body->GetDepartureBoardResponse->GetStationBoardResult)) {
		error_log("NRE unexpected response ($label): " . substr($xml, 0, 300));
		return null;
	}
	return $doc->Body->GetDepartureBoardResponse->GetStationBoardResult;
}

/**
 * Fetch several departure boards in parallel.
 * $boards maps a key to [crs, filterCrs|null]. Returns the same keys mapped
 * to a GetStationBoardResult element, or null where that board failed.
 */
function nre_fetch_boards(array $boards): array {
	$results = array_fill_keys(array_keys($boards), null);
	$pending = [];

	foreach ($boards as $key => [$crs, $filterCrs]) {
		$cached = nre_cache_read($crs, $filterCrs);
		if ($cached !== null && ($result = nre_parse($cached, "$crs cache"))) {
			$results[$key] = $result;
		} else {
			$pending[$key] = [$crs, $filterCrs];
		}
	}

	if (!$pending || !($token = nre_token())) {
		return $results;
	}

	$multi   = curl_multi_init();
	$handles = [];
	foreach ($pending as $key => [$crs, $filterCrs]) {
		$ch = curl_init(NRE_URL);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => nre_request_body($token, $crs, $filterCrs),
			CURLOPT_HTTPHEADER     => ['Content-Type: application/soap+xml; charset=utf-8; action="http://thalesgroup.com/RTTI/2017-10-01/ldb/GetDepartureBoard"'],
			CURLOPT_CONNECTTIMEOUT => 4,
			CURLOPT_TIMEOUT        => 8,
		]);
		curl_multi_add_handle($multi, $ch);
		$handles[$key] = $ch;
	}

	do {
		$status = curl_multi_exec($multi, $running);
		// select() can return -1 immediately on some systems; pause briefly rather than spin
		if ($running && curl_multi_select($multi) === -1) {
			usleep(50000);
		}
	} while ($running && $status === CURLM_OK);

	foreach ($handles as $key => $ch) {
		[$crs, $filterCrs] = $pending[$key];
		$label = $crs . '→' . ($filterCrs ?? 'all');
		$body  = (string) curl_multi_getcontent($ch);
		$code  = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

		if ($code !== 200) {
			error_log("NRE request failed ($label): HTTP $code " . curl_error($ch) . ' ' . substr($body, 0, 300));
		} elseif ($result = nre_parse($body, $label)) {
			$results[$key] = $result;
			nre_cache_write($crs, $filterCrs, $body);
		}

		curl_multi_remove_handle($multi, $ch);
	}
	curl_multi_close($multi);

	return $results;
}

/**
 * Build a human-readable destination string.
 * Handles split/joint services where multiple <location> nodes are present.
 */
function nre_destination_names(SimpleXMLElement $destinationNode): string {
	$names = [];
	if (isset($destinationNode->location)) {
		foreach ($destinationNode->location as $loc) {
			$names[] = (string) $loc->locationName;
		}
	}
	return implode(' & ', $names) ?: 'Check Board';
}

/** Classify an estimated departure as 'ontime', 'cancelled' or 'delayed'. */
function nre_status(string $etd): string {
	return ['On time' => 'ontime', 'Cancelled' => 'cancelled'][$etd] ?? 'delayed';
}

/** Minutes late, or 0 when the estimate isn't a later clock time. */
function nre_delay_minutes(string $std, string $etd): int {
	$pattern = '/^([01]?\d|2[0-3]):([0-5]\d)$/';
	if (!preg_match($pattern, $std, $s) || !preg_match($pattern, $etd, $e)) {
		return 0;
	}
	$diff = ($e[1] * 60 + $e[2]) - ($s[1] * 60 + $s[2]);
	// Handle midnight rollover (e.g. due 23:55, expected 00:10)
	if ($diff < -720) {
		$diff += 1440;
	}
	return max(0, $diff);
}

/**
 * Turn an NRCC disruption message into safe HTML: plain text plus
 * http(s) links only, with every other tag and attribute removed.
 */
function nre_clean_message(SimpleXMLElement $message): string {
	// The feed carries HTML escaped inside XML, so decoding once gives HTML source
	$decode = fn(string $s) => html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$html   = $decode($message->asXML());
	$links  = [];

	// Swap each link for a placeholder so strip_tags/escaping leaves it alone
	$html = preg_replace_callback('/<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)<\/a>/is', function ($m) use (&$links, $decode) {
		$href = $decode($m[2]);
		$text = trim($decode(strip_tags($m[3])));
		if (!preg_match('#^https?://#i', $href)) {
			return h($text);
		}
		$links[] = [$href, $text];
		return "\x01" . (count($links) - 1) . "\x02";
	}, $html);

	$text = h(trim(preg_replace('/\s+/', ' ', $decode(strip_tags($html)))));

	return preg_replace_callback('/\x01(\d+)\x02/', function ($m) use ($links) {
		[$href, $label] = $links[$m[1]];
		return '<a href="' . h($href) . '" target="_blank" rel="noopener noreferrer">' . h($label) . '</a>';
	}, $text);
}

/** Flatten a GetStationBoardResult into plain arrays for the views and API. */
function nre_board(SimpleXMLElement $result): array {
	$board = [
		'location'       => (string) $result->locationName,
		'filterLocation' => (string) $result->filterLocationName,
		'messages'       => [],
		'services'       => [],
	];

	if (isset($result->nrccMessages->message)) {
		foreach ($result->nrccMessages->message as $m) {
			$board['messages'][] = nre_clean_message($m);
		}
	}

	if (isset($result->trainServices->service)) {
		foreach ($result->trainServices->service as $s) {
			$std = (string) $s->std ?: '--:--';
			$etd = (string) $s->etd ?: 'Delayed';
			$board['services'][] = [
				'std'         => $std,
				'etd'         => $etd,
				'status'      => nre_status($etd),
				'delay'       => nre_delay_minutes($std, $etd),
				'platform'    => (string) $s->platform,
				'destination' => nre_destination_names($s->destination),
				'operator'    => (string) $s->operator,
				'length'      => (int) $s->length ?: null,
			];
		}
	}

	return $board;
}
