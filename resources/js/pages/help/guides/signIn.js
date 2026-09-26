export default {
    slug: 'sign-in',
    category: 'start',
    title: 'Sign in and find your way around',
    summary: 'Open TindaFlow, sign in with your own account, learn what the dashboard shows, and sign out safely.',
    roles: ['CASHIER', 'MANAGER', 'ADMIN'],
    startHere: ['CASHIER', 'MANAGER', 'ADMIN'],
    minutes: 3,
    before: ['You have your **email** and **password** from the store owner or an admin.', 'You are using the computer or tablet the store set aside for TindaFlow.'],
    steps: [
        {
            title: 'Enter your email and password',
            body: 'On the sign-in page, click the **Email** box and type your email. Then click the **Password** box and type your password. Click **Sign in**.',
            figure: {
                shot: 'signin-login',
                alt: 'The TindaFlow sign-in page with an Email box, a Password box and a Sign in button.',
                marks: [
                    { type: 'type', target: 'email', label: 'Type your email', detail: 'Click the Email box and type your email address, for example', text: 'cashier@demo.local' },
                    { type: 'type', target: 'password', label: 'Type your password', detail: 'Click the Password box and type your password. It shows as dots so nobody can read it over your shoulder.', text: '••••••••••' },
                    { type: 'click', target: 'submit', label: 'Click Sign in', detail: 'Click Sign in.' },
                ],
            },
            dos: ['Type your own email and password every time you start work.', 'Check the spelling of your email if the page says it does not match.'],
            donts: ['Don’t share your password or sign in as someone else. Every sale is recorded under the name that signed in.'],
            tip: 'Too many wrong tries in a row makes the page ask you to wait a little before trying again. Wait a minute, then try once more slowly.',
        },
        {
            title: 'Look around the dashboard',
            body: 'After you sign in you land on the **Dashboard**. The menu on the left lists every place you are allowed to go. Big buttons and tiles in the middle take you to the same places.',
            figure: {
                shot: 'signin-dashboard',
                alt: 'The Dashboard with the menu on the left and today’s sales figures in the middle.',
                marks: [
                    { type: 'look', target: 'menu', label: 'Your menu', detail: 'The menu lists only what your role can use. A cashier sees fewer items than a manager or an admin.' },
                    { type: 'look', target: 'today', label: 'Today so far', detail: 'Today’s sales at a glance. Managers and admins see this; cashiers do not.' },
                    { type: 'click', target: 'open-till', label: 'Click Open the till', detail: 'Click Open the till when you are ready to start selling. See “Start your shift”.' },
                ],
            },
            note: 'The figures on your own screen are your store’s real numbers. The pictures in these guides use sample data.',
        },
        {
            title: 'What a cashier sees',
            body: 'A cashier’s dashboard is deliberately short: the till, and the sales history for looking up an earlier sale. Everything else in the menu belongs to managers and admins.',
            figure: {
                shot: 'signin-cashier',
                alt: 'A cashier’s Dashboard showing only the till and Sales history.',
                marks: [
                    { type: 'click', target: 'open-till', label: 'Open the till', detail: 'This is your starting point every day.' },
                    { type: 'look', target: 'menu', label: 'A short menu', detail: 'If a place you need is missing, ask a manager or admin. They can change your role.' },
                ],
            },
        },
        {
            title: 'Sign out when you step away',
            body: 'Click **Sign out** at the bottom of the menu when you finish, or when someone else is about to use the till. Signing out does not close your shift or lose any sale.',
            figure: {
                shot: 'signin-dashboard',
                alt: 'The Sign out button at the bottom of the left menu.',
                marks: [
                    { type: 'click', target: 'sign-out', label: 'Click Sign out', detail: 'Click Sign out at the bottom of the menu.' },
                    { type: 'avoid', target: 'open-till', label: 'Not this one', detail: 'Don’t leave the till open on screen and walk away. Sign out first.' },
                ],
            },
            warning: 'Just closing the browser tab does not sign you out. Use Sign out so the next person cannot act as you.',
        },
    ],
    done: 'You can sign in, read the dashboard and sign out. Next, cashiers should learn how to start a shift.',
    next: [],
};
