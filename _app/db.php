<?php
function db(array $cfg, bool $withoutDb = false): PDO {
  $charset = 'utf8mb4';
  $dsn = $withoutDb
    ? "mysql:host={$cfg['db_host']};port={$cfg['db_port']};charset=$charset"
    : "mysql:host={$cfg['db_host']};port={$cfg['db_port']};dbname={$cfg['db_name']};charset=$charset";

  return new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);
}
