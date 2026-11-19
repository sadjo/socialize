// Script du site public de Socialize, commun aux pages anglaise et française : les textes affichés viennent de la
// page (attributs data-*), le script ne contient aucune phrase.
(function () {
  // Copier la commande d'installation ; libellé « copié » pris sur le bouton (data-done).
  document.querySelectorAll('[data-copy]').forEach(function (button) {
    var label = button.textContent;
    button.addEventListener('click', function () {
      var source = document.getElementById(button.dataset.copy);
      var select = function () { window.getSelection().selectAllChildren(source); };
      if (!navigator.clipboard) return select();
      navigator.clipboard.writeText(source.textContent).then(function () {
        button.textContent = button.dataset.done || label;
        setTimeout(function () { button.textContent = label; }, 1600);
      }, select);
    });
  });

  // Thème clair ou sombre, gardé d'une visite à l'autre.
  var root = document.documentElement, key = 'socialize-site-theme';
  try { var saved = localStorage.getItem(key); if (saved) root.dataset.theme = saved; } catch (e) {}
  var theme = document.getElementById('theme');
  if (theme) {
    theme.addEventListener('click', function () {
      var dark = root.dataset.theme ? root.dataset.theme === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches;
      root.dataset.theme = dark ? 'light' : 'dark';
      try { localStorage.setItem(key, root.dataset.theme); } catch (e) {}
    });
  }

  // Trajet d'une photo : « grande plateforme » ou « chez vous » ; la phrase de conclusion suit (data-verdict-*).
  var journey = document.getElementById('journey');
  if (journey) {
    var scene = journey.querySelector('.scene');
    var verdict = journey.querySelector('.verdict');
    var buttons = journey.querySelectorAll('.switch button');
    buttons.forEach(function (button) {
      button.addEventListener('click', function () {
        var mode = button.dataset.mode;
        journey.dataset.mode = mode;
        scene.dataset.mode = mode;
        verdict.textContent = verdict.dataset[mode];
        buttons.forEach(function (other) { other.setAttribute('aria-pressed', String(other === button)); });
      });
    });
  }

  // Liste de sécurité : les cases se cochent une à une quand elle arrive à l'écran (déjà cochées sans script).
  var list = document.getElementById('checklist');
  if (list && 'IntersectionObserver' in window) {
    new IntersectionObserver(function (entries, observer) {
      if (!entries[0].isIntersecting) return;
      list.classList.add('anim');
      observer.disconnect();
    }, { threshold: 0.4 }).observe(list);
  }
})();
