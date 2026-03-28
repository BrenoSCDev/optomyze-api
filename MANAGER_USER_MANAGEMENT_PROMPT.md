# Optomyze Manager — User Management Feature Prompt

## Context
The Optomyze Manager is a separate frontend app (built in Lovable/React) that uses a shared secret header (`X-Manager-Key`) to interact with a protected Laravel API. It already has full **Company management** (list, create, edit, deactivate, delete, restore).

You need to add **User management** to the Manager app, mirroring the same UX pattern used for companies.

---

## API Reference

All endpoints require the header: `X-Manager-Key: <secret>`
Base URL prefix: `/manager`

### List users for a company
```
GET /manager/users?company_id={id}
```
Optional filters: `?active=true|false`, `?role=admin|manager|agent`

Response: array of user objects
```json
[
  {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com",
    "phone": "+55...",
    "role": "admin",
    "is_active": true,
    "last_login_at": "2026-03-01T10:00:00.000000Z",
    "created_at": "2026-01-15T08:00:00.000000Z"
  }
]
```

### Show a single user
```
GET /manager/users/{id}
```

### Create a user
```
POST /manager/users
Content-Type: application/json

{
  "company_id": 1,         // required — which company this user belongs to
  "name": "Jane Smith",    // required
  "email": "jane@co.com",  // required, unique
  "phone": "+55...",       // optional
  "role": "manager",       // required — admin | manager | agent
  "is_active": true,       // optional, defaults to true
  "password": "secret123"  // optional — defaults to "changeme" if omitted
}
```
Response: 201 with created user object.

### Edit a user
```
PUT /manager/users/{id}
Content-Type: application/json

{
  "name": "Jane Updated",
  "email": "new@email.com",
  "phone": "+55...",
  "role": "agent",
  "is_active": true,
  "password": "newpass"   // optional — only hash & update if provided
}
```
All fields are optional (PATCH semantics, uses PUT verb).

### Deactivate a user
```
PATCH /manager/users/{id}/deactivate
```
Sets `is_active = false`. No body needed.
Response:
```json
{ "message": "User deactivated successfully", "user": { ... } }
```

### Delete a user (permanent)
```
DELETE /manager/users/{id}
```
Response: `{ "message": "User deleted successfully" }`

---

## UI Requirements

### Navigation
Add a **"Users"** section to the Manager sidebar, right below "Companies".

### Users List Page (`/manager/users`)
- A **company selector** (dropdown or search-select) at the top — required before users load. Pre-populate from the existing companies list (`GET /manager/companies`).
- Table columns: Name, Email, Phone, Role (badge), Status (Active/Inactive badge), Last Login, Actions.
- Filter bar: Status toggle (All / Active / Inactive) and Role dropdown (All / Admin / Manager / Agent).
- Action buttons per row:
  - **Edit** → opens edit modal/drawer
  - **Deactivate** → calls `PATCH /manager/users/{id}/deactivate`, confirm dialog first
  - **Delete** → calls `DELETE /manager/users/{id}`, confirm dialog (destructive, red)
- **"+ Add User"** button in the top-right that opens the create modal.

### Create User Modal
Fields:
- Company (pre-filled from current selector, read-only if already selected)
- Name (required)
- Email (required)
- Phone (optional)
- Role — select: Admin / Manager / Agent (required)
- Active — toggle (default on)
- Password (optional, placeholder: "Leave blank to use default 'changeme'")

### Edit User Modal / Drawer
Same fields as create, pre-filled with current values. Company field is read-only (cannot move a user between companies). Password field: leave blank to keep existing password.

### Role Badges
- `admin` → purple
- `manager` → blue
- `agent` → gray

### Status Badges
- `is_active: true` → green "Active"
- `is_active: false` → red "Inactive"

---

## Existing Pattern to Follow
Look at how Companies are listed, created, and edited in the Manager app — the User management pages should feel identical in layout and interaction. Re-use the same:
- Table component
- Modal/drawer pattern
- Confirm dialog for destructive actions
- Toast notifications for success/error
- The `X-Manager-Key` auth header injection (already configured in the API client)
