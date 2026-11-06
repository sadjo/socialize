/* Message bref affiché en bas de l'écran. */
export function toast(message) {
    document.querySelector('.toast')?.remove();
    const element = document.createElement('div');
    element.className = 'toast';
    element.setAttribute('role', 'status');
    element.textContent = message;
    document.body.appendChild(element);
    setTimeout(() => element.remove(), 3000);
}
