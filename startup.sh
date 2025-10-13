#!/bin/bash

# Spustit Laravel a Vite současně na pozadí
php artisan serve --host=rozhlas.lan &
npm run dev &

# Krátká prodleva, aby měly servery čas naběhnout
sleep 3

# Otevřít stránku v prohlížeči (Linux: xdg-open, Mac: open)
if command -v xdg-open >/dev/null; then
  xdg-open "http://rozhlas.lan:8000"
elif command -v open >/dev/null; then
  open "http://rozhlas.lan:8000"
else
  echo "Otevři ručně: http://rozhlas.lan:8000"
fi

# Udržet skript živý, dokud běží oba procesy
wait