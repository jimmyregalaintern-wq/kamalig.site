KAMALIG landing page - media slots
==================================
Drop licensed photos / video here using EXACTLY these names. No code change is needed:
landing/index.php checks for each file and renders the slot only when it exists.
(.md files are blocked by .htaccess, which is why this is a .txt file.)

HERO (highest priority)
  hero-poster.webp + hero-poster.jpg   1920x1080 (16:9), landscape, subject on the RIGHT half
                                       (copy sits on the left). Target <= 220 KB webp / 300 KB jpg.
  hero-rice.mp4  (H.264, 1280x720, 8-15 s seamless loop, no audio, <= 3 MB, +faststart)
  hero-rice.webm (VP9, same cut, <= 2 MB)   <- optional but recommended
  Video is only fetched on desktop-class screens, never with reduced-motion / data-saver.
  Suggested footage: farmers transplanting or harvesting in a paddy, sacks being loaded, warehouse stacking.

SECTION PHOTOS (each .webp + .jpg, 1200x900 / 4:3, <= 150 KB webp)
  story-warehouse   Capabilities section  - workers stacking rice sacks in a warehouse
  story-logistics   Supply-chain band     - rice trucks / loading / distribution
  aud-farmers       Audience card         - Filipino farmers in the field (3:2, 1000x667)
  aud-importers     Audience card         - port / container / import inspection (3:2)
  aud-suppliers     Audience card         - procurement officer at a warehouse / market (3:2)
  cta-field         Closing CTA backdrop  - wide rice terraces (1920x800)

Licensing: use only photos/footage you own or have licensed (e.g. your own shoots, DA/PhilRice
with permission, or Pexels/Unsplash/Pixabay items whose licence allows commercial use). Keep the
licence/credit in your records. Alt text for each slot lives in the $kgMedia array in landing/index.php.

Convert examples:  cwebp -q 78 in.jpg -o story-warehouse.webp
                   ffmpeg -i in.mp4 -an -vf scale=1280:-2 -c:v libx264 -crf 28 -preset slow -movflags +faststart hero-rice.mp4
