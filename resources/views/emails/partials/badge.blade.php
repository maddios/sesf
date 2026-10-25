<?php
$__sesfBadgeText = isset($text) ? (string) $text : '';
$__sesfBadgeKind = isset($kind) ? (string) $kind : 'info';
$__sesfBadgeStyles = [
    'success' => ['bg' => '#dcfce7', 'fg' => '#15803d', 'border' => '#bbf7d0'],
    'info' => ['bg' => '#dbeafe', 'fg' => '#1d4ed8', 'border' => '#bfdbfe'],
    'warning' => ['bg' => '#fef3c7', 'fg' => '#a16207', 'border' => '#fde68a'],
    'danger' => ['bg' => '#fee2e2', 'fg' => '#b91c1c', 'border' => '#fecaca'],
    'neutral' => ['bg' => '#f1f5f9', 'fg' => '#475569', 'border' => '#e2e8f0'],
];
$__sesfBadge = isset($__sesfBadgeStyles[$__sesfBadgeKind]) ? $__sesfBadgeStyles[$__sesfBadgeKind] : $__sesfBadgeStyles['info'];
?>
<span style="display:inline-block;background:{{ $__sesfBadge['bg'] }};color:{{ $__sesfBadge['fg'] }};border:1px solid {{ $__sesfBadge['border'] }};border-radius:999px;padding:4px 12px;font-size:11px;font-weight:600;letter-spacing:0.06em;text-transform:uppercase;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;">{{ $__sesfBadgeText }}</span>
