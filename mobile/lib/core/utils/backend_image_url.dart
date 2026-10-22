import '../config.dart';

/// Laravel serves `/storage/...` via `php artisan storage:link`, which
/// exposes `storage/app/public/*` at `public/storage/*` (note: the URL
/// segment `app/public` is dropped after symlink).
///
/// Backend rows however may store paths in two flavours:
///
///   A. Symlink form (correct):   /storage/brand/hero.jpg
///   B. Disk-relative form:        /storage/app/public/brand/hero.jpg
///
/// We normalise both into the correct public URL the web server can
/// serve (form A). This avoids a 403 from Laravel while still being
/// tolerant of legacy data.
String backendImageUrl(String? value, {String? baseUrl}) {
  final raw = value?.trim() ?? '';
  if (raw.isEmpty) return '';

  // Absolute http(s) URL — pass through untouched.
  // We check BEFORE any slash normalisation because `https://...` is a
  // valid URL with a scheme but no leading slash.
  final rawLower = raw.toLowerCase();
  if (rawLower.startsWith('http://') || rawLower.startsWith('https://')) {
    return raw;
  }

  var path = raw;

  // Strip leading slash so we can safely replace prefixes.
  if (path.startsWith('/')) path = path.substring(1);

  // Drop the redundant `app/public` segment if present.
  // e.g. storage/app/public/brand/x.jpg  →  storage/brand/x.jpg
  if (path.startsWith('storage/app/public/')) {
    path = 'storage/${path.substring('storage/app/public/'.length)}';
  } else if (path.startsWith('app/public/')) {
    path = 'storage/${path.substring('app/public/'.length)}';
  }

  // Re-add the leading slash so URI parsing works correctly.
  final normalised = '/$path';

  final uri = Uri.tryParse(normalised);
  if (uri == null) return '';

  // Resolve against the backend origin (no /api prefix).
  final origin = Uri.parse(baseUrl ?? AppConfig.apiBaseUrl).resolve('/');
  return origin.resolveUri(uri).toString();
}
