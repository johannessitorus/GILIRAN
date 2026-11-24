
# GILIRAN

Employee Shift Swap & Handover Management System

GILIRAN is a web-based application designed to manage employee shift schedules, shift exchange requests, supervisor approvals, and work handovers between consecutive shifts.

## Project Objectives

- Manage employee shift schedules.
- Support shift swap requests between employees.
- Validate scheduling conflicts and shift eligibility.
- Provide supervisor approval workflows.
- Document shift handovers and pending tasks.
- Maintain an audit trail of important actions.

## User Roles

- **Admin:** Manages users, departments, shift configurations, and system settings.
- **Supervisor:** Manages team schedules, reviews shift swap requests, and monitors handovers.
- **Employee:** Views assigned shifts, requests exchanges, and manages handover reports.

## Technology Stack

- Frontend: React, TypeScript, Tailwind CSS
- Backend: Laravel REST API
- Database: PostgreSQL
- Authentication: Laravel Sanctum
- Testing: PHPUnit, with Playwright planned for frontend flows

## Development Status

- Stage 1: Requirements and MVP planning — Documented
- Stage 2: System architecture and database design — Documented
- Stage 3: Backend development — Scheduling, swaps, handovers, tasks, and audit implemented
- Stage 4: Frontend development — Not started

## Documentation

- [Software Requirements](docs/01_requirements.txt)
- [System Design](docs/02_system_design.txt)
- [API and Acceptance Criteria](docs/03_api_and_acceptance.md)
- [Backend Setup and Tests](backend/README.md)

## License

No license has been selected yet.
