# GV Contact Form Pro

A secure, modular, and performance-optimized AJAX contact form plugin for WordPress — now featuring a full visual builder and Gutenberg block integration.

## Overview
**GV Contact Form Pro** handles form creation, spam defense, and message storage without external dependencies.  
Version 2 introduces visual styling controls, live preview, and block-editor support.

## Key Features

### 🧩 Form Building
- **Visual Form Builder:** Add, remove, and reorder fields with an intuitive interface.  
- **Live Preview Shortcode:** `[gv_contact_form_live_preview]` shows real-time output as you style.
- **Gutenberg Block:** Insert and configure the form directly in the WordPress block editor.

### 💾 Data Handling
- **CPT Storage:** Submissions stored as private posts (`gv_message`) for clean database management.  
- **CSV Export:** One-click export with nonce verification for admins.

### 🛡️ Security
- **Multi-Layer Spam Defense:** Honeypot + rate limiting + reCAPTCHA v3 validation.  
- **Nonce Protection:** All AJAX actions and CSV exports validated via secure nonces.  
- **GDPR Ready:** Optional consent checkbox with customizable label.

### ⚙️ Styling Options
- **Customizable Visuals:** Control background color / opacity, blur, border width / style / radius / color, padding, shadows, and button presets.  
- **Dynamic Themes:** Combine presets for dark, glass, or minimal form looks.

### ⚡ Performance
- **Optimized Loading:** Scripts and reCAPTCHA load only where needed.  
- **Lightweight Footprint:** No database overhead or third-party tracking.

### 🧠 Developer Notes
- Shortcodes:  
  - `[gv_contact_form]` – production form  
  - `[gv_contact_form_live_preview]` – real-time admin preview  
- Block: `gardevault/contact-form` (script `gv-contact-form-block`)  
- AJAX Action: `gv_submit_contact`  
- CSV Action: `gv_forms_export`

---

**Author:** GardeVault  
**Website:** [https://gardevault.com](https://gardevault.com)  
**Version:** 2.0  
**License:** GPL-2.0-or-later
