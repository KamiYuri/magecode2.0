# MageCode 2.0 — UI/UX Design Specification v1.0

> **Version**: 1.0 — 10/08/2026
> **Authors**: Gideon & Claude (Anthropic)
> **Status**: Design SoT for `services/web` (roadmap Plan F)
> **Sources**: `api-contracts/openapi.yml` (contract), `technical-design.md` §2–§5 (domain, roles,
> visibility), decision log D-05/06/09/24/28/41/44/62 + amendments (decisions-v3 §7)

---

## 1. Product & Audience

MageCode is the code-assessment platform for HUST courses (BKCS/SoICT; first users IT3080,
IT4062). Three audiences with opposing needs shape every screen:

- **Students** work under deadline pressure. They need one path with zero ambiguity: find the
  problem, submit, watch the verdict arrive. Speed and reassurance over density.
- **Instructors/TAs** grade at scale. They need dense, scannable tables, bulk actions, and
  evidence they can trust when confronting a student about plagiarism.
- **Admins** (Org/System) need oversight: policies, cross-section dashboards, approvals.

**Design stance:** calm academic rigor. The UI is the referee, not the coach — verdicts are
presented as evidence (counts, percentages, highlighted regions), never as accusation. No
gamification, no celebration animations, no mascots.

## 2. Visual Identity

### 2.1. Signature element — the Verdict Strip

The per-test-case streaming result (D-81) is MageCode's most distinctive capability, so it is
the visual signature. A **verdict strip** is a horizontal row of small square cells, one per
test case, that fill in real time as `execution.updated` events arrive:

```
Bài 3 — Danh sách liên kết      ██ ██ ██ ▓▓ ░░ ░░ ░░ ░░ ░░ ░░   3/10 · đang chấm
                                 ↑pass  ↑fail ↑pending
```

The strip appears identically everywhere a submission is summarized: the student's problem
page, submission history rows, the instructor's submission table, and (aggregated) on
dashboard cards. It is the product's fingerprint — implement it once
(`components/verdict/VerdictStrip.vue`) and reuse it; never substitute a progress bar.

### 2.2. Color tokens

Neutral shadcn-vue `new-york`/`neutral` base (already locked in `components.json`), one brand
accent, and a strictly semantic verdict palette. Brand red is reserved for identity; it is
NEVER used for errors, so "Bách khoa crimson" and "failure red" stay distinguishable.

| Token | Light | Dark | Usage |
|---|---|---|---|
| `--brand` | `#A6192E` (Bách khoa crimson) | `#C43B4E` | Logo, active nav marker, primary CTA |
| `--surface` | `#FAFAF9` | `#151517` | Page background |
| `--panel` | `#FFFFFF` | `#1E1E21` | Cards, tables, editor chrome |
| `--ink` | `#1C1C1E` | `#E8E8E6` | Body text |
| `--verdict-pass` | `#15803D` | `#4ADE80` | accepted |
| `--verdict-fail` | `#B91C1C` | `#F87171` | wrong_answer, error |
| `--verdict-limit` | `#B45309` | `#FBBF24` | time/memory_limit_exceeded, timeout |
| `--verdict-pending` | `#A1A1AA` | `#52525B` | in_queue, processing (pulses) |
| `--flag-similarity` | `#7C3AED` | `#A78BFA` | similarity ≥ threshold, AI ≥ threshold badges |

Integrity flags (purple) are deliberately outside the pass/fail axis: a flagged submission is
*suspicious*, not *wrong* — the color system encodes that distinction.

### 2.3. Typography

| Role | Face | Rationale |
|---|---|---|
| UI (all text) | **Be Vietnam Pro** (400/500/600) | Vietnamese-designed grotesque; flawless diacritics for the vi-first UI; not the default Inter |
| Code, verdicts, IDs, timestamps | **JetBrains Mono** (400/600) | Editor consistency with Monaco; tabular figures for scores |

Headings are Be Vietnam Pro 600 with tight tracking — no separate display face; the mono face
appearing inside prose (scores, MSSV, `20252`) is the typographic personality. Type scale:
12 / 13 (base, dense tables) / 14 (body) / 16 / 20 / 28. Base is intentionally compact:
instructor tables are the most-used surface.

### 2.4. Mode & motion

- Light mode default (classrooms, projectors); dark mode supported and synced to Monaco theme
  (`vs` / `vs-dark`). Persisted per user.
- Motion budget: verdict-cell fill (120ms), row highlight on WebSocket update, skeleton
  shimmer. Nothing else animates. `prefers-reduced-motion` disables all three.

## 3. Information Architecture

### 3.1. Navigation model (D-09)

Role-based dashboard at `/`; global left sidebar (collapsible, icons + labels) whose items
depend on the user's memberships — a user can be instructor in one section and student in
another, so **gating is per-resource, never per-user** (see §7).

- **Student**: 1 click to problem — dashboard lists enrolled sections with problem groups inline.
- **Instructor**: 2 clicks — dashboard lists sections with stats → section workspace.
- **Org Admin**: org overview → drill down anywhere; extra "Tổng quan học vụ" analytics area.
- **System Admin**: platform organizations area (`/admin`).

### 3.2. Route map (Vue Router)

| Route | Screen | Roles |
|---|---|---|
| `/login`, `/register`, `/quen-mat-khau`, `/thiet-lap-lan-dau` | Auth screens | public / first-time |
| `/` | Role dashboard | all |
| `/sections/:id` | Section workspace (student view: problem list; staff view: tabs Bài toán · Thành viên · Bài nộp · Phân tích) | member |
| `/problems/:id` | Problem detail: statement + Monaco + submission history (student); + edit/test-cases/publish-lock (staff) | member |
| `/problems/:id/submissions/:sid` | Submission detail: code + verdict strip + per-test-case table | owner / staff |
| `/analysis/:id` | Analysis batch: progress, tabs Trùng lặp · AI · Lỗ hổng | staff |
| `/analysis/:id/similarity/:pairId` | Similarity pair viewer (two-tier, §6.3) | staff |
| `/courses/:id` | Course: semesters, tags, bank entry point | instructor+ |
| `/courses/:id/bank`, `/bank-problems/:id` | Problem bank: browse/preview/clone/publish/versions/approve | instructor+ |
| `/semesters/:id` | Semester: sections, policies, `analysis-overview`, `match-groups` | org admin (policies), instructor (read) |
| `/organizations/:id` | Org: courses, members | org admin |
| `/admin` | Platform organizations | system admin |
| `/thong-bao`, `/tai-khoan` | Notifications, profile | all |

## 4. Screen Specifications

### 4.1. Student dashboard (`/`)

```
┌ Sidebar ┬──────────────────────────────────────────────┐
│ Trang   │  IT3080 — Lớp L01                            │
│ chủ     │  ┌ Tuần 5 ────────────────────────────────┐  │
│ Thông   │  │ ● Bài 1: Stack        ██████████  10/10 │  │
│ báo     │  │ ● Bài 2: Queue        ███▓░░░░░░   3/10 │  │
│ Tài     │  │ ○ Bài 3: Linked List  chưa nộp · còn 2d │  │
│ khoản   │  └─────────────────────────────────────────┘  │
│         │  ┌ Tuần 6 ─── (locked: mở 15/08) ──────────┐  │
└─────────┴──────────────────────────────────────────────┘
```

- Problems grouped by `group_label`, ordered by `order` (D-44). Locked/unpublished groups
  render collapsed with the activation date — visible future work reduces anxiety.
- Row = name + verdict strip of best submission + deadline countdown (`còn 2 ngày`, turns
  `--verdict-limit` amber under 24h). Data: `GET /me/sections`.

### 4.2. Problem detail — student (`/problems/:id`)

Two-pane: left = statement (rich text + KaTeX render), sample test cases (`is_visible=true`)
with copy buttons; right = Monaco editor with language select (only the problem's allowed
languages), file-upload alternative (drag-drop, max 50KB, extension checked client-side),
submit button showing quota `Nộp bài (3/5)`. Below: submission history table (verdict strip,
language, time, `is_outdated` badge `Bộ test đã thay đổi` per D-41).

Submit → row appears instantly in history with pending strip → cells fill via
`private-submission.{id}` events → final toast `Đã chấm xong: 7/10 test`. No page reload at
any point. When `max_submissions` is reached the submit button disables with reason text, and
when `lock_time` passes the editor becomes read-only with banner `Đã hết hạn nộp bài`.

### 4.3. Section workspace — staff (`/sections/:id`)

Tabs: **Bài toán** (list + create/clone from bank + reorder by drag), **Thành viên** (roster,
CSV/Excel import wizard with row-level error report, transfer dialog), **Bài nộp**
(cross-problem table, filters: problem/student/status, `all | best` toggle mirroring §7.5 of
technical-design, export Excel), **Phân tích** (batch history + trigger).

### 4.4. Analysis trigger & progress (`/analysis/:id`)

Trigger dialog (from problem or section): service checkboxes — Trùng lặp ✅, AI ✅, Lỗ hổng ⬜
(defaults per D-24) — plus scope preview: "So sánh trong toàn kỳ: 4 lớp · 156 bài nộp" so the
cross-section scope (D-48) is never a surprise. If the problem is unlocked, a warning marks
the run partial (D-57). If a completed batch exists, offer `Xem kết quả có sẵn` vs `Chạy lại`.

Progress screen: one card per service with `completed_count/total_count` from
`analysis.progress` events; batch timeout (D-82) renders as amber state with per-submission
results preserved (D-59). Results tabs:

- **Trùng lặp**: pair table sorted by similarity desc; rows ≥ `similarity_threshold` (D-62)
  get the purple flag. Columns: student A, student B (+ section badge if cross-section), %,
  match_type chip, action `Xem so sánh`.
- **AI**: per-submission probability with flag ≥ `ai_detection_threshold`; distribution
  strip (not a chart page — one glanceable row).
- **Lỗ hổng**: findings grouped by severity (`error`/`warning`/`recommendation`), each linking
  to the file/line in a read-only Monaco with gutter markers.

### 4.5. Similarity pair viewer — the two-tier screen (D-05/D-06)

**Within-section** (`match_type = WITHIN_SECTION`): side-by-side read-only Monaco panes, both
names shown, matched regions (`a_regions`/`b_regions`) highlighted in linked scroll.

**Cross-section**: left pane = own student's code with highlights; right pane = **redaction
panel**: the other student's name, section, and % are shown, but the code area renders a
locked-state card: `Mã nguồn thuộc lớp khác — không hiển thị theo chính sách phân quyền`, with
the escalation action `Báo cáo lên Quản trị đơn vị`. The redaction is presented as policy, not
as a broken screen — this is the most sensitive UX moment in the product; it must feel
deliberate.

**Org Admin** sees both panes always, plus section labels on each side.

### 4.6. Problem bank (`/courses/:id/bank`)

Gallery/table with tag + difficulty + language filters and keyword search (D-68). Preview
drawer (rich text + sample tests, read-only, D-69) with primary action `Nhân bản vào lớp…`
(section picker). Publish flow shows approval status when `require_bank_approval` (pending →
Org Admin approves from notification). Version history list per original (D-63): each version
row shows author, date, `Nhân bản phiên bản này`.

### 4.7. Admin screens

Semester policy form (publish/lock modes + override toggles, D-16) with plain-language helper
text for each combination; `analysis-overview` dashboard (flagged pairs across sections);
`match-groups` manager — pick equivalent problems across sections to link into one
`manual_match_group_id` (D-58) via a two-column picker. System Admin: organizations CRUD +
Org Admin assignment.

## 5. Real-time UX Conventions (U-8)

| Channel | Event | UI reaction |
|---|---|---|
| `private-submission.{id}` | `execution.updated` | Fill verdict cell(s), update `x/y` counter |
| `private-submission.{id}` | `execution.completed` | Finalize strip, toast with result summary |
| `private-section.{id}` | `analysis.progress` | Update service progress card counts |
| `private-section.{id}` | `analysis.completed` | Toast + refresh results tabs if open |

Rules: WebSocket updates mutate Pinia stores — components never refetch on events unless the
payload is a signal-only (then one targeted refetch). On reconnect, refetch the affected
resource once (missed events are tolerable; DB is truth). Connection loss shows a quiet
status-bar chip `Mất kết nối trực tiếp — kết quả sẽ cập nhật khi tải lại`, never a modal.

## 6. Component & State Standards

- **Base kit**: shadcn-vue (new-york), lucide icons, Tailwind 4 tokens from §2.2. Custom
  components live in `components/verdict/`, `components/similarity/`, `components/editor/`.
- **Tables**: cursor pagination ("Tải thêm"), sticky header, mono for numeric columns, column
  filters server-side. Bulk selection only where a bulk action exists.
- **Forms**: server errors map field-by-field from the `{ message, errors }` envelope;
  editability follows the server-driven editable-fields metadata (openapi §problem detail) —
  the client never re-implements policy logic to decide what is disabled.
- **Empty states**: one sentence naming the next action + that action as a button
  (`Chưa có bài toán nào — Tạo bài toán đầu tiên hoặc Nhân bản từ ngân hàng đề`).
- **Loading**: skeletons mirroring final layout; never spinners on full pages.
- **Errors**: banner with cause + retry; error text states what happened, no apology filler.
- **Destructive ops**: typed-confirmation dialog only for irreversible actions (delete
  section/course); soft-deletes (problem, bank problem) use a standard confirm with an
  explicit mention that submissions are preserved (D-52).

## 7. Roles & Gating on the Client

Frontend receives the user's memberships and each resource's `permissions`/editable metadata
from the API. Rules:

1. Route guards check membership presence only (can this user open this section at all).
2. Action visibility comes from per-resource server metadata, not from role names — a TA may
   be granted extra actions per section (D-13); the server decides.
3. Never hide integrity data by client logic alone: cross-section code redaction (§4.5) must
   be enforced by the API response shape; the client merely renders the redaction panel when
   code is absent.

## 8. Language & Copy

- UI strings are **Vietnamese-first** (product serves Vietnamese users; per project language
  policy). Code, identifiers, and this document remain English. i18n scaffolding (vue-i18n,
  `vi` as the only shipped locale in Phase F) keeps an `en` door open without committing to it.
- Terminology matches the domain glossary exactly (technical-design §2.3): Đơn vị, Môn học,
  Kỳ triển khai, Lớp, Bài toán, Bài nộp, Ngân hàng đề. Never introduce synonyms — the UI
  vocabulary is how users learn the domain model.
- Verdict labels stay technical and untranslated where the community expects English:
  `Accepted`, `Wrong Answer`, `TLE` render as-is with Vietnamese tooltips.
- Buttons name the action's effect (`Nộp bài`, `Khóa bài toán`, `Chạy phân tích`); the toast
  echoes the same verb (`Đã khóa bài toán`). Sentence case throughout; no exclamation marks.
- Dates: `dd/MM/yyyy HH:mm` (24h, Asia/Ho_Chi_Minh); relative time only under 24h (`3 phút
  trước`), always with absolute tooltip.

## 9. Accessibility & Responsive

- WCAG 2.1 AA: 4.5:1 contrast (verify verdict colors on both surfaces), visible focus rings,
  full keyboard paths for every flow. Monaco gets a documented `Esc` escape hatch to exit the
  editor tab-trap; verdict strips carry `aria-label="7 trên 10 test đúng"`.
- Verdict state never relies on color alone: cells use fill + glyph (✓ ✕ ◷) at small sizes.
- Breakpoints: desktop-first (≥1280 primary for staff), student flows fully usable at 768+
  (tablet). Below 768: read-only mode — statements, results, and history render; the editor
  collapses to "Mở trên máy tính để làm bài" with file-upload still available. Tables collapse
  to card lists on mobile.

## 10. Decision Traceability

| Decision | UI consequence |
|---|---|
| D-05/D-06 two-tier similarity | Redaction panel + escalation action (§4.5) |
| D-09 dashboards per role | Route map + click-depth budget (§3) |
| D-13 TA optional powers | Server-driven action gating (§7) |
| D-24 VUL off by default | Trigger dialog defaults (§4.4) |
| D-28 Vue 3 + shadcn-vue + Tailwind | Component standards (§6) |
| D-41 outdated submissions | `Bộ test đã thay đổi` badge (§4.2) |
| D-44 order + group_label | Dashboard grouping (§4.1) |
| D-48/D-57/D-59 semester scope, partial, degradation | Scope preview, partial warning, amber timeout state (§4.4) |
| D-52 submissions permanent | Delete-dialog copy (§6) |
| D-62 per-semester thresholds | Purple flag logic (§4.4) |
| D-81 per-test-case streaming | Verdict strip signature (§2.1, §5) |
| U-5 five roles | System Admin area (§3.2, §4.7) |
| U-8 channels/events | Real-time conventions (§5) |

---

*— End of UI/UX Design Specification v1.0 —*
