/* Maps Grid Tracker admin: confirmations, report map, settings preview. */
(function () {
  'use strict';

  var l10n = window.mgtL10n || {};

  document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-mgt-confirm]');
    if (el && !window.confirm(el.getAttribute('data-mgt-confirm'))) {
      e.preventDefault();
    }
  });

  function tiles(map) {
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
    }).addTo(map);
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function format(tpl, args) {
    return tpl.replace(/%(\d)\$[ds]/g, function (m, n) { return args[n - 1]; });
  }

  /* Report map */
  var report = window.mgtReport;
  var reportEl = document.getElementById('mgt-map');
  if (report && reportEl && window.L) {
    var map = L.map(reportEl, { scrollWheelZoom: false });
    tiles(map);
    var bounds = [];
    report.points.forEach(function (p) {
      var cls = 'mgt-none';
      var label = '–';
      if (!p.ok) {
        cls = 'mgt-error';
        label = '?';
      } else if (p.rank > 0) {
        cls = p.rank <= 3 ? 'mgt-top3' : (p.rank <= 10 ? 'mgt-top10' : 'mgt-top20');
        label = String(p.rank);
      }
      var icon = L.divIcon({ className: 'mgt-pin ' + cls, html: '<span>' + esc(label) + '</span>', iconSize: [30, 30], iconAnchor: [15, 15] });
      var html = '<strong>' + esc(l10n.point) + ' ' + p.i + '</strong><br>';
      if (!p.ok) {
        html += esc(l10n.error);
      } else {
        html += esc(l10n.position) + ': ' + (p.rank > 0 ? p.rank : esc(l10n.notFound));
        if (p.top && p.top.length) {
          html += '<br><br><em>' + esc(l10n.top) + '</em><ol class="mgt-popup-top">';
          p.top.forEach(function (t) {
            html += '<li' + (t.ours ? ' class="is-ours"' : '') + '>' + esc(t.title) + (t.rating ? ' <span>★ ' + esc(Number(t.rating).toFixed(1)) + (t.votes != null ? ' (' + esc(t.votes) + ')' : '') + '</span>' : '') + '</li>';
          });
          html += '</ol>';
        }
      }
      L.marker([p.lat, p.lng], { icon: icon, title: l10n.point + ' ' + p.i }).bindPopup(html).addTo(map);
      bounds.push([p.lat, p.lng]);
    });
    if (bounds.length) {
      map.fitBounds(bounds, { padding: [30, 30] });
    } else {
      map.setView(report.center, 12);
    }
  }

  /* Settings preview */
  var previewEl = document.getElementById('mgt-preview');
  if (window.mgtSettings && previewEl && window.L) {
    var $ = function (id) { return document.getElementById(id); };
    var lat = $('mgt-lat'), lng = $('mgt-lng'), size = $('mgt-size'), spacing = $('mgt-spacing');
    var keywords = $('mgt-keywords'), price = $('mgt-price'), estimate = $('mgt-estimate');
    var pmap = L.map(previewEl, { scrollWheelZoom: false });
    tiles(pmap);
    var layer = L.layerGroup().addTo(pmap);
    var fitted = false;

    var num = function (input) { return parseFloat(String(input.value).replace(',', '.')); };

    var draw = function (refit) {
      layer.clearLayers();
      var la = num(lat), ln = num(lng);
      var center = isFinite(la) && isFinite(ln) ? [la, ln] : window.mgtSettings.fallback;
      var n = parseInt(size.value, 10) || 7;
      var step = num(spacing) || 1;
      var dlat = step / 110.574;
      var dlng = step / (111.320 * Math.max(0.01, Math.cos(center[0] * Math.PI / 180)));
      var half = Math.floor(n / 2);
      var pts = [];
      for (var r = 0; r < n; r++) {
        for (var c = 0; c < n; c++) {
          var pt = [center[0] + (half - r) * dlat, center[1] + (c - half) * dlng];
          pts.push(pt);
          L.circleMarker(pt, { radius: 5, color: '#2271b1', weight: 1, fillColor: '#2271b1', fillOpacity: 0.6 }).addTo(layer);
        }
      }
      L.circleMarker(center, { radius: 9, color: '#d63638', weight: 2, fillColor: '#fff', fillOpacity: 1 }).bindTooltip(l10n.center).addTo(layer);
      if (refit || !fitted) {
        pmap.fitBounds(pts, { padding: [20, 20] });
        fitted = true;
      }
      var kw = keywords.value.split('\n').filter(function (k) { return k.trim() !== ''; }).length;
      var total = n * n * kw;
      var cost = total * (num(price) || 0);
      estimate.textContent = format(l10n.estimate || '', [n * n, kw, total, cost.toFixed(2)]);
    };

    pmap.on('click', function (e) {
      lat.value = e.latlng.lat.toFixed(6);
      lng.value = e.latlng.lng.toFixed(6);
      draw(false);
    });
    [lat, lng, size, spacing].forEach(function (el) { el.addEventListener('change', function () { draw(true); }); });
    [keywords, price].forEach(function (el) { el.addEventListener('input', function () { draw(false); }); });
    draw(true);
  }
})();
