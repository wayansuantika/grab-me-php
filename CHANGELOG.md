# GrabMas Changelog

## [Current] - Gallery Image Management & Admin Settings Control

### Added
- **Gallery Image Management** — Full admin control over all 8 gallery images
  - Added 16 new settings fields (8 image URLs + 8 captions) to admin accordion
  - Gallery images now stored in database instead of hardcoded
  - Admin can edit images and captions anytime from Site Settings panel
  - Default fallback images if fields are empty

- **Admin Settings Panel** — Compact accordion interface for site customization
  - Brand & Logo section (logo text, image URL)
  - HP2 Hero section (desktop/mobile images, title, subtitle, proof chips)
  - HP2 Services section (label, title, link text)
  - HP2 Gallery section (label, title, 8 image URLs + captions)
  - HP2 FAQ section (label, title, side image)

- **Settings-Driven Homepage** — All visible content configurable via admin
  - Hero section with trust chips
  - Services showcase (reads from service categories)
  - Gallery section (reads from database settings)
  - FAQ section (reads from settings)
  - Footer with booking/login links

### Modified
- `public/assets/js/app.js`
  - Expanded HP2 Gallery accordion with 8 image+caption field pairs
  - Updated `homeTemplate()` to read gallery images from settings with fallback defaults
  - Form handler automatically saves all new gallery settings to database

### How to Use
1. Navigate to **Admin Panel** → **Site Settings**
2. Expand **HP2 Gallery** accordion
3. Fill in Image URLs and Captions for all 8 items
4. Click **Save Settings**
5. Homepage refreshes with new gallery content

---

## Previous Sessions - Full Feature Implementation

### Audit Viewer (Completed)
- Backend audit logging with admin_logs table
- Audit viewer panel (admin-only, with pagination)
- Card-based layout with color-coded actions
- User IP tracking and formatted timestamps

### Mobile Responsiveness (Completed)
- Hamburger menu with explicit JS visibility control
- Responsive admin table layouts (therapist, services, areas, bookings, payments)
- Toast notification system (success, danger, warning, info)
- Full-width action buttons on mobile

### Homepage Redesign (Completed)
- Complete HP2 template with Bali aesthetic
- Centered logo with optional brand image
- Hero section with social proof
- Services showcase section
- Gallery section (now dynamic via settings)
- FAQ section with side image
- CTA section

### Technical Foundation
- PHP 8+ with strict types
- MySQL/MariaDB (database: "grabme")
- Bootstrap 5.3.3
- Vanilla JS SPA with hash routing
- Service/Repository architecture
- Session-based auth with role checking
- FileCache for performance
