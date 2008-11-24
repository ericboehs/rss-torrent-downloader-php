<?

// example.php
// A demonstration of how to use the tracker/torrent library to perform a basic query on
// a tracker about a torrent.

if(isset($_GET['lookup'])) {
	require_once "lib/bt.config.php";
	require_once "lib/torrent.php";
	require_once "lib/tracker.php";

	$torrent = new Torrent("test/[isoHunt] Nine Inch Nails - Ghosts I (2008).torrent");

	// Query the trackers in the torrent file
	// The timeout parameter defaults to 5 seconds, increasing it will possibly allow
	// more accurate queries but it can also be painfully slow if the torrent has several
	// trackers that are too slow to resolve.
	$scrape_result = tracker_scrape_all($torrent, 5);
	$summary = tracker_scrape_summarise($scrape_result);
}

?>

<? if(isset($_GET['lookup'])): ?>
<h1><?=$torrent->name; ?></h1>

<p>
	<? if(isset($torrent->files)): ?>
		<? foreach($torrent->files as $file): ?>
			<?=$file->name; ?><br>
		<? endforeach; ?>
	<? endif; ?>
</p>

<p>
	<b>Seeds:</b> <?=$summary->seeds; ?><br>
	<b>Leeches:</b> <?=$summary->leeches; ?><br>
	<b>Total Size:</b> <?=sprintf("%01.2f", $torrent->totalSize / 1073741824); ?>GB<br>
	<? if(is_array($torrent->modifiedBy)): ?>
		<b>Modified By:</b> <?=implode(", ", $torrent->modifiedBy); ?>
	<? elseif($torrent->modifiedBy): ?>
		<b>Modified By:</b> <?=$torrent->modifiedBy; ?>
	<? endif; ?>
</p>

<h2>Trackers</h2>
<ul>
	<? foreach($scrape_result as $tracker => $result): ?>
		<? if($result): ?>
			<li style="color:#0f0;"><?=$tracker; ?> <?="(Seeds: {$result->seeds}, Leeches: {$result->leeches})"; ?></li>
		<? else: ?>
			<li style="color:#f00;"><?=$tracker; ?></li>
		<? endif; ?>
	<? endforeach; ?>
</ul>
<? else: ?>

<h1>Loading...</h1>
<p>
	<em>Loading the information from the example .torrent. This make take a while the first time you load the page.</em>
</p>

<script language="javascript">
	document.location.href = 'example.php?lookup';
</script>

<? endif; ?>