# Certi3 Module

This document summarizes the current backend implementation of the Certi3 workflow so operators and developers can interact with the new APIs safely.

## Overview
- Tracks certificate requests inside table `certi_requests` with audit trail in `certi_logs` and API traces in `certi_api_logs`.
- Business logic lives in `app/Services/Certi/CertiWorkflowService.php`, with HTTP clients per provider (DocuEngine, VisEngine, Catasto).
- Files produced by providers or uploaded manually are stored under `storage/certi/<request-id>/` and the relative path is persisted in `certi_requests.file_certificato`.

## Roles & Permissions
Only users with roles `Admin`, `Manager`, or `Operatore` can access the API entry point `api/certi/index.php`. The same restriction should be enforced by any UI consuming these endpoints.

## Environment Requirements
Set the following keys in `.env` (production secrets must be injected securely):

```
DOCUENGINE_BASE_URL=https://...
DOCUENGINE_API_KEY=...
DOCUENGINE_TOKEN=...
VISENGINE_BASE_URL=https://...
VISENGINE_API_KEY=...
CATASTO_BASE_URL=https://...
CATASTO_USERNAME=...
CATASTO_PASSWORD=...
```

All storage paths default to `<project-root>/storage/certi`. Override by passing `storagePath` when instantiating `CertiWorkflowService` if needed for CLI scripts or workers.

## API Actions
Invoke `api/certi/index.php` via AJAX or server-side calls. Requests must include `action` and follow the table below.

| Action | Method | Body parameters | Description |
| --- | --- | --- | --- |
| `list` | GET | `status`, `assigned_to`, pagination filters | Returns filtered requests with summary counters. |
| `create` | POST JSON | Request payload (see migration for fields) | Validates input, auto-assigns operator if not provided. |
| `update` | PUT/PATCH JSON | `id`, fields to mutate | Edits editable columns and writes log entry. |
| `assign` | POST JSON | `id`, `operator_id` | Forces a new assignee and logs the change. |
| `status` | POST JSON | `id`, `status` | Allowed values: `nuova`, `in_validazione`, `in_lavorazione`, `in_attesa_api`, `completata`, `respinta`, `errore_api`. |
| `submit` | POST JSON | `id`, `payload` | Sends the request to the auto-selected provider and stores remote request IDs. |
| `fetch_document` | POST JSON | `id` | Downloads the PDF from the provider, persists it locally, and marks the request `completata`. |
| `upload_certificate` | POST multipart | `id`, `file` | Accepts a manual PDF upload, saves it under `storage/certi`, and completes the request. |
| `get_certificate` | GET/POST JSON | `id` | Returns `{name, content (base64), content_type}` for rendering or triggering a download on the frontend. |

### Sample Request Snippets

Submit to provider:
```bash
curl -X POST -H "Content-Type: application/json" -b cookies.txt \
  -d '{"action":"submit","id":123,"payload":{"protocollo":"ABC"}}' \
  https://example.com/api/certi/index.php
```

Manual upload (multipart):
```bash
curl -X POST -b cookies.txt \
  -F action=upload_certificate -F id=123 -F file=@/path/to/doc.pdf \
  https://example.com/api/certi/index.php
```

Retrieve stored file (base64 response to be handled by UI):
```bash
curl -X GET -b cookies.txt "https://example.com/api/certi/index.php?action=get_certificate&id=123"
```

## Logging & Auditing
- Every workflow mutation writes an entry via `CertiLogService` for traceability.
- External API calls are journaled in `certi_api_logs` with payload, response, status code, retry count, and success flag. Review via SQL when debugging provider failures.

## Storage Notes
- All directories are created lazily; ensure the `storage/certi` folder is writable by the PHP process.
- Filenames are sanitized and prefixed with a timestamp to keep a unique, chronological order.
- `getCertificateFile` includes extra guards against path traversal; only relative paths under the project root are accepted.

## Next Steps
- Connect these APIs to the dashboard UI (filters, detail sidebar, upload/download buttons).
- Add automated tests around `CertiWorkflowService` to cover provider download failures and manual uploads.
- Evaluate background workers for polling provider statuses if synchronous responses are not guaranteed.
