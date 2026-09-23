import './bootstrap';

// Bundled via npm/Vite instead of a <script src="https://cdnjs..."> tag, so
// the dashboard's charts aren't at the mercy of an external CDN being
// reachable (a firewall, an ad blocker, or just being offline will silently
// leave the canvases blank if Chart.js has to load from the internet).
import Chart from 'chart.js/auto';

window.Chart = Chart;
