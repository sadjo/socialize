// Point d'entrée JavaScript de Socialize, chargé par AssetMapper via importmap().
import './stimulus_bootstrap.js';
import './styles/app.css';

// Zoom fixe sur iPhone : Safari ignore « user-scalable=no » pour le pincement, on l'empêche donc ici.
for (const type of ['gesturestart', 'gesturechange', 'gestureend']) {
    document.addEventListener(type, (event) => event.preventDefault(), { passive: false });
}
