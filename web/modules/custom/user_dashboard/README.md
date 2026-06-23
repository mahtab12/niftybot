# User Dashboard (Drupal 10/11)

Provides a custom user dashboard with:
- Route at `/user/dashboard`
- Redirect to dashboard after login (intercepts `/user` landing)
- Controller-driven 2-column layout (left menu, right content)
- Themeable Twig template

## Install
1. Copy `user_dashboard` into `web/modules/custom/` (or `/modules/custom/` depending on your docroot).
2. Clear caches: `drush cr`.
3. Enable: `drush en user_dashboard -y` (or via Extend UI).
4. Login; you should be redirected to `/user/dashboard`.

## Customize
- Edit `UserDashboardController::dashboard()` to build dynamic data or menus.
- Replace inline styles in `templates/user-dashboard.html.twig` with theme/library CSS.
