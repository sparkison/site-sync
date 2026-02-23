import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Default sidebar to collapsed on each load (don't persist state across page loads)
localStorage.setItem('isOpen', JSON.stringify(false));
