# QR Photo Upload — Personal Demo

## Problem and users
An organizer needs to collect photos from guests without accounts or installing an app. An authenticated admin creates a collection, shares its QR code, and reviews photos privately. Anyone holding an active upload link may contribute but cannot browse the gallery.

## MVP
Create and close upload sessions; expiring QR links; mobile multiple-photo upload; private admin gallery; authorized original download and deletion; audit events. English UI and documentation for portfolio use.

## Proposed defaults
JPEG, PNG and WebP only, 10 MB per image, 10 images per request, 100 images per session, 24-hour default expiry. These are implementation assumptions, not requirements provided by a client. No public gallery, guest accounts, video, HEIC, paid plans, ZIP export, cloud integration or thumbnails in this release.

## Success
A real phone can open a reachable QR URL and upload photos; the admin sees persisted images after reload. Expired/closed sessions and unauthorized gallery access are rejected by the server. No production users or client outcomes are claimed.

## Workload assumptions
One instance, one organizer initially, fewer than 100 active sessions, 10 concurrent guest requests. Targets (unmeasured): metadata requests below 500 ms locally; upload latency depends on network and file size. 100 images × 10 MB is about 1 GB per collection. Production quotas and retention need explicit policy before public deployment.
