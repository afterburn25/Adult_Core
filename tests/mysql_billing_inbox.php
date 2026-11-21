<?php

declare(strict_types=1);

/**
 * Disposable MySQL integration for verified payment event identity.
 * Never configure this command against an installed/customer database.
 */
require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Billing\WebhookInbox;
use AdultCore\Database\Schema;

if (getenv('AC_TEST_DB_CONFIRM') !== 'YES_THIS_IS_A_DISPOSABLE_DATABASE') {
    fwrite(STDERR, "Refusing billing integration test without disposable-database confirmation.\n");
    exit(2);
}
$host=getenv('AC_TEST_DB_HOST') ?: '127.0.0.1';
$port=getenv('AC_TEST_DB_PORT') ?: '3306';
$name=getenv('AC_TEST_DB_NAME');
$user=getenv('AC_TEST_DB_USER');
$pass=getenv('AC_TEST_DB_PASSWORD');
if (!is_string($name) || !is_string($user) || !is_string($pass)
    || $name === '' || $user === '' || !ctype_digit((string)$port)
    || (int)$port < 1 || (int)$port > 65535) {
    throw new RuntimeException('Disposable billing test database credentials invalid.');
}
$pdo=new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
    $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
Schema::assertFresh($pdo);
$checks=0;
$check=static function(bool $ok, string $message)use(&$checks):void {
    if(!$ok)throw new RuntimeException('Billing test failed: '.$message);
    ++$checks;
};
$refuse=static function(callable $fn,string $message)use($check):void {
    try{$fn();}catch(RuntimeException){$check(true,$message);return;}
    throw new RuntimeException('Billing test failed: '.$message);
};
try{
    WebhookInbox::migrate($pdo);
    $inbox=new WebhookInbox($pdo);
    $event='sandbox_evt_123456';
    $hash=hash('sha256', 'immutable simulated payment payload');
    $different=hash('sha256', 'different payload');
    $check($inbox->recordVerified('sandbox',$event,'payment.confirmed',$hash),
        'First verified event inserted');
    $check(!$inbox->recordVerified('sandbox',$event,'payment.confirmed',$hash),
        'Exact verified retry is idempotent');
    $refuse(static fn()=> $inbox->recordVerified('sandbox',$event,'payment.refunded',$hash),
        'Conflicting event type rejected');
    $refuse(static fn()=> $inbox->recordVerified('sandbox',$event,'payment.confirmed',$different),
        'Conflicting payload digest rejected');
    $check($inbox->recordVerified('other_provider',$event,'payment.confirmed',$different),
        'Independent provider event namespace');
    $check((int)$pdo->query('SELECT COUNT(*) FROM ac_billing_events')->fetchColumn() === 2,
        'Only distinct verified events persisted');
    $row=$pdo->query("SELECT event_type,payload_sha256 FROM ac_billing_events WHERE provider='sandbox'")
        ->fetch(PDO::FETCH_ASSOC);
    $check(is_array($row) && $row['event_type']==='payment.confirmed'
        && $row['payload_sha256']===$hash, 'Conflicting retry never mutates stored event');
    foreach([
        ["sandbox\ninjection",$event,'payment.confirmed',$hash],
        ['sandbox',"bad\nid",'payment.confirmed',$hash],
        ['sandbox',$event,"payment\nconfirmed",$hash],
        ['sandbox',$event,'payment.confirmed','not-a-digest'],
    ] as [$provider,$eventId,$type,$digest]){
        $refuse(static fn()=> $inbox->recordVerified($provider,$eventId,$type,$digest),
            'Unsafe metadata rejected');
    }
    echo "Passed {$checks} disposable MySQL verified-event replay checks.\n";
}finally{
    // This script creates exactly one empty-test table, never a real site.
    $pdo->exec('DROP TABLE IF EXISTS ac_billing_events');
}
