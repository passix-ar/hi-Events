export const Constants = {
    // An arbitrary to represent an infinite number of tickets
    INFINITE_TICKETS: 999999999,
    // Typed verbatim to confirm a destructive action, in every language. Keep it out
    // of the message catalogs: translating it leaves the prompt asking for a word the
    // check will never accept.
    DELETE_CONFIRMATION_WORD: 'delete',
    // Ancho a partir del cual el sidebar del panel deja de ser overlay y pasa a
    // ocupar su propia columna de 280px. Tiene que coincidir con
    // $app-shell-breakpoint de styles/mixins.scss.
    APP_SHELL_BREAKPOINT: 992
}
