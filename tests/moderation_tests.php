<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Moderation\ModerationRepository;
use AdultCore\Moderation\ModerationSchema;

$checks=0;
function expect(bool $yes,string $name): void {
    global $checks;
    if (!$yes) throw new RuntimeException('FAIL: '.$name);
    $checks++;
}
expect(ModerationSchema::VERSION==='m8_moderation_review_v1','Versioned migration');
expect(ModerationRepository::validateTitle('  Gallery  ')==='Gallery','Title trimmed');
expect(ModerationRepository::validateNote('  Reason for quarantine.  ')==='Reason for quarantine.','Review note trimmed');
expect(ModerationRepository::validateReportReason('  I need this reviewed.  ')==='I need this reviewed.','Report reason trimmed');
foreach (['','  ',str_repeat('a',191),"unsafe\0title"] as $title) {
    try { ModerationRepository::validateTitle($title);throw new RuntimeException('accepted'); }
    catch (RuntimeException $e) { expect($e->getMessage()!=='accepted','Bad title blocked'); }
}
foreach (['short','',str_repeat('b',501),"bad\x01 note longer"] as $note) {
    try { ModerationRepository::validateNote($note);throw new RuntimeException('accepted'); }
    catch (RuntimeException $e) { expect($e->getMessage()!=='accepted','Bad review note blocked'); }
}
foreach (['','too short',str_repeat('c',1001),"bad\x01 reason longer"] as $reason) {
    try { ModerationRepository::validateReportReason($reason);throw new RuntimeException('accepted'); }
    catch (RuntimeException $e) { expect($e->getMessage()!=='accepted','Bad report reason blocked'); }
}
echo "Passed $checks moderation validation checks.\n";
