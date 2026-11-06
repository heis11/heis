<?php
// Navigation for signed-in admins. $active is the key of the current page.
$nav = [
    'dashboard' => ['index.php', 'Dashboard'],
    'movies'    => ['movies.php', 'Movies'],
    'add'       => ['movie-add.php', 'Add movie'],
    'search'    => ['search-online.php', 'Search online'],
    'sources'   => ['sources.php', 'Sources'],
    'settings'  => ['settings.php', 'Settings'],
];
?>
<aside class="side">
  <a class="brand" href="index.php">HEISVERSE</a>
  <p class="who">Signed in as <b><?= h($admin['username'] ?? '') ?></b></p>
  <nav aria-label="Admin">
<?php foreach ($nav as $key => [$href, $label]): ?>
    <a href="<?= h($href) ?>"<?= ($active ?? '') === $key ? ' class="on" aria-current="page"' : '' ?>><?= h($label) ?></a>
<?php endforeach; ?>
    <a href="../" target="_blank" rel="noopener">View public site</a>
  </nav>
  <form method="post" action="logout.php" class="out">
    <?= csrf_field() ?>
    <button class="btn line sm" type="submit">Sign out</button>
  </form>
</aside>
