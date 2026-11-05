<?php
/*
 * Template: copy this file to public_html/index.php, then fill in the two
 * station codes below. Your copy is git-ignored, so your stations stay private.
 */
require __DIR__ . '/lib/nre.php';

// Your two stations, as three-letter CRS codes (for example, PAD is London Paddington)
const STATION_A = ''; // e.g. the station nearest home
const STATION_B = ''; // e.g. the station nearest work

if (STATION_A === '' || STATION_B === '') {
	http_response_code(500);
	exit('Add your two station codes to the top of index.php.');
}

// Origin => destination. Each route is one board; the toggle switches between them.
const ROUTES = [STATION_A => STATION_B, STATION_B => STATION_A];

$requests = [];
foreach (ROUTES as $origin => $destination) {
	$requests[$origin] = [$origin, $destination];
}
$results      = nre_fetch_boards($requests);
$defaultBoard = array_key_first(ROUTES);
$updated      = date('H:i:s');

// Only this page's own nonce-tagged scripts/styles may run
$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'nonce-$nonce'; style-src 'self' 'nonce-$nonce'; object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
?>
<!DOCTYPE html>
<html lang="en-GB" data-board="<?= h($defaultBoard) ?>">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title>Trains</title>
<link rel="icon" type="image/png" href="/trains/favicon-96x96.png" sizes="96x96" />
<link rel="icon" type="image/svg+xml" href="/trains/favicon.svg" />
<link rel="shortcut icon" href="/trains/favicon.ico" />
<link rel="apple-touch-icon" sizes="180x180" href="/trains/apple-touch-icon.png" />
<meta name="apple-mobile-web-app-title" content="Trains" />
<link rel="manifest" href="/trains/site.webmanifest" />

<script nonce="<?= $nonce ?>">
// Restore the last board viewed before first paint, so auto-refreshes don't flash the default
(function () {
	try {
		var saved = localStorage.getItem('trains.board');
		if (<?= json_encode(array_keys(ROUTES)) ?>.indexOf(saved) !== -1) {
			document.documentElement.dataset.board = saved;
		}
	} catch (e) {}
})();
</script>
<link rel="stylesheet" href="/trains/style.css">
<style nonce="<?= $nonce ?>">
<?php foreach (array_keys(ROUTES) as $i => $crs): ?>
	html[data-board="<?= h($crs) ?>"] .board[data-board="<?= h($crs) ?>"] { display: block; }
	html[data-board="<?= h($crs) ?>"] .toggle-btn[data-board="<?= h($crs) ?>"] { color: #fff; }
	html[data-board="<?= h($crs) ?>"] .toggle-pill { transform: translateX(<?= $i * 100 ?>%); }
<?php endforeach; ?>
</style>
</head>

<body>

<button type="button" class="refresh" id="refresh" aria-label="Refresh departures">
	<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg>
</button>

<main class="page">
	<h1 class="sr-only">Trains between <?= h(STATION_A) ?> and <?= h(STATION_B) ?></h1>

<?php foreach (ROUTES as $origin => $destination): ?>
	<section class="board" data-board="<?= h($origin) ?>">
	<?php if (!$results[$origin]): ?>
		<header class="board-head"><h2><?= h($origin) ?></h2></header>
		<div class="board-empty"><div class="board-empty-icon" aria-hidden="true">⚠️</div><p>Could not fetch departure data.</p></div>
	<?php else: $board = nre_board($results[$origin]); ?>
		<header class="board-head">
			<h2><?= h($board['location'] ?: $origin) ?></h2>
			<h3><span aria-hidden="true">➡</span><span class="sr-only">to</span> <?= h($board['filterLocation'] ?: $destination) ?></h3>
		</header>

		<?php if ($board['messages']): ?>
		<div class="board-messages">
			<?php foreach ($board['messages'] as $message): ?>
			<p><span aria-hidden="true">⚠️</span> <?= $message /* already sanitised by nre_clean_message() */ ?></p>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>

		<?php if (!$board['services']): ?>
		<div class="board-empty"><div class="board-empty-icon" aria-hidden="true">🕒</div><p>No services found.</p></div>
		<?php else: ?>
		<table class="services">
			<thead class="sr-only">
				<tr><th scope="col">Due</th><th scope="col">Destination</th><th scope="col">Status</th><th scope="col">Platform</th></tr>
			</thead>
			<tbody>
			<?php foreach ($board['services'] as $s): ?>
				<tr class="service service--<?= $s['status'] ?>">
					<td class="service-time"><?= h($s['std']) ?></td>
					<td class="service-dest"><div><?= h($s['destination']) ?></div></td>
					<td class="service-status">
						<?= h($s['etd']) ?>
						<?php if ($s['delay'] > 0): ?><span class="service-delay"><?= $s['delay'] ?> min delay</span><?php endif; ?>
					</td>
					<td class="service-plat"><span><?= h($s['platform'] ?: '—') ?></span></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
	<?php endif; ?>
		<footer class="board-foot">Updated: <?= $updated ?></footer>
	</section>
<?php endforeach; ?>

	<div class="toggle" role="group" aria-label="Direction">
		<div class="toggle-pill"></div>
		<?php foreach (array_keys(ROUTES) as $crs): ?>
		<button type="button" class="toggle-btn" data-board="<?= h($crs) ?>" aria-pressed="false"><?= h($crs) ?></button>
		<?php endforeach; ?>
	</div>

</main>

<script nonce="<?= $nonce ?>">
const BOARD_KEY = 'trains.board';
const buttons   = document.querySelectorAll('.toggle-btn');

function syncButtons() {
	const current = document.documentElement.dataset.board;
	buttons.forEach(b => b.setAttribute('aria-pressed', String(b.dataset.board === current)));
}

buttons.forEach(btn => btn.addEventListener('click', () => {
	document.documentElement.dataset.board = btn.dataset.board;
	try { localStorage.setItem(BOARD_KEY, btn.dataset.board); } catch (e) {}
	syncButtons();
}));

syncButtons();

document.getElementById('refresh').addEventListener('click', () => window.location.reload());

// Auto-refresh every 60 seconds so the board stays live
setTimeout(() => window.location.reload(), 60_000);
</script>
</body>
</html>
