<?php

namespace App\Service;

use App\Config;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

trait MessageBuilderTrait
{
  private function buildTitleFromProps(array $props, array $row): string
  {
    $event = $props['event'] ?? ($row['event'] ?? 'Weather Alert');
    $headline = $props['headline'] ?? ($row['headline'] ?? $event);
    return sprintf('[%s] %s', strtoupper((string)$event), $headline);
  }

  private function buildMessageFromProps(array $props, array $row): string
  {
    $fields = [
      'Msg' => $props['messageType'] ?? ($row['msg_type'] ?? null),
      'Status' => $props['status'] ?? ($row['status'] ?? null),
      'Category' => $props['category'] ?? ($row['category'] ?? null),
      'Severity' => $props['severity'] ?? ($row['severity'] ?? null),
      'Certainty' => $props['certainty'] ?? ($row['certainty'] ?? null),
      'Urgency' => $props['urgency'] ?? ($row['urgency'] ?? null),
      'Area' => $props['areaDesc'] ?? ($row['area_desc'] ?? null),
      'Effective' => $props['effective'] ?? ($row['effective'] ?? null),
      'Expires' => $props['expires'] ?? ($row['expires'] ?? null),
    ];
    $lines = [];
    $lines[] = sprintf('S/C/U: %s/%s/%s', $fields['Severity'] ?? '-', $fields['Certainty'] ?? '-', $fields['Urgency'] ?? '-');
    $lines[] = sprintf('Status/Msg/Cat: %s/%s/%s', $fields['Status'] ?? '-', $fields['Msg'] ?? '-', $fields['Category'] ?? '-');
    $lines[] = sprintf('Area: %s', $fields['Area'] ?? '-');
    $lines[] = sprintf('Time: %s → %s',
      $this->formatLocalTime($fields['Effective'] ?? null),
      $this->formatLocalTime($fields['Expires'] ?? null)
    );

    $desc = $props['description'] ?? ($row['description'] ?? null);
    if ($desc) {
      $lines[] = '';
      $lines[] = (string)$desc;
    }

    $instr = $props['instruction'] ?? ($row['instruction'] ?? null);
    if ($instr) {
      $lines[] = '';
      $lines[] = 'Instruction: ' . $instr;
    }

    return implode("\n", array_filter($lines, fn($l) => $l !== null));
  }

  private function formatLocalTime($iso8601OrNull): string
  {
    if (!$iso8601OrNull || !is_string($iso8601OrNull)) {
      return '-';
    }
    try {
      $dt = new DateTimeImmutable($iso8601OrNull);
      $tz = new DateTimeZone(Config::$timezone ?: 'UTC');
      $local = $dt->setTimezone($tz);
      return $local->format('Y-m-d H:i');
    } catch (Throwable $e) {
      return (string)$iso8601OrNull;
    }
  }
}
