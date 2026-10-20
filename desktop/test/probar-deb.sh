set -e
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq >/dev/null
echo "== instalación limpia =="
apt-get install -y -qq /pkg.deb >/dev/null 2>/tmp/err.txt || { cat /tmp/err.txt; exit 1; }
dpkg -l cotizaciones-escritorio | tail -1
echo "== archivos instalados =="
ls /opt/Cotizaciones/cotizaciones-escritorio /usr/share/applications/cotizaciones-escritorio.desktop
grep -E "^(Name|Exec|Icon|Categories|StartupWMClass)=" /usr/share/applications/cotizaciones-escritorio.desktop
echo "== arranque (Xvfb, sin GPU) =="
apt-get install -y -qq xvfb dbus-x11 >/dev/null 2>&1
mkdir -p /root/datos
cd /tmp
COTIZACIONES_URL=http://127.0.0.1:9 xvfb-run -a timeout 12 /opt/Cotizaciones/cotizaciones-escritorio --no-sandbox --disable-gpu --user-data-dir=/root/datos > /tmp/salida.txt 2>&1 || true
echo "salida (primeras líneas relevantes):"; grep -vE "dbus|libva|vaapi|GLib|Gtk|ERROR:(bus|object_proxy)|sandbox_linux|gpu_|viz_|vulkan|angle" /tmp/salida.txt | head -5
test -d /root/datos && echo "carpeta de datos creada (la app llegó a iniciar): $(ls /root/datos | head -3 | tr '\n' ' ')"
echo "== desinstalación =="
apt-get remove -y -qq cotizaciones-escritorio >/dev/null 2>&1
test ! -e /opt/Cotizaciones/cotizaciones-escritorio && echo "binario eliminado"; test ! -e /usr/share/applications/cotizaciones-escritorio.desktop && echo "acceso del menú eliminado"
dpkg -l cotizaciones-escritorio 2>&1 | tail -1
