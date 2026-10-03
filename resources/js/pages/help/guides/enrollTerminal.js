export default {
    slug: 'enroll-terminal',
    category: 'setup',
    title: 'Enroll a till (terminal)',
    summary: 'A browser can only sell once it has been enrolled as a terminal. An admin issues a one-time token and enters it on the till computer.',
    roles: ['ADMIN'],
    startHere: ['ADMIN'],
    minutes: 4,
    before: [
        'You are signed in as an **Admin**.',
        'You are at the computer or tablet that will be the till, or you can copy a short code to it.',
        'You know what to call the till (for example **TILL-1**). If it is not in the list yet, you add it in the first step.',
    ],
    steps: [
        {
            title: 'Know why a browser needs enrolling',
            body: 'Every sale, shift and stock change is recorded against a **terminal**, so the system must know which till it came from. A browser that is not enrolled can look around but cannot sell. If someone opens the till on an un-enrolled browser they see this message.',
            figure: {
                shot: 'till-not-enrolled',
                alt: 'A message saying this browser is not enrolled as any terminal.',
                marks: [{ type: 'look', target: 'message', label: 'Not enrolled', detail: 'This message means the browser has no terminal credential yet. An admin enrolls it in the next steps.' }],
            },
        },
        {
            title: 'Open Terminals and add the till',
            body: 'Click **Terminals** in the menu. If your till is not in the list, type its name under **Add terminal** (for example **TILL-1**) and click **Add terminal**. Each till needs its own name, and two tills cannot share one.',
            figure: {
                shot: 'terminals-add',
                alt: 'The Add terminal form with the till name TILL-1 typed in.',
                marks: [
                    { type: 'type', target: 'name', label: 'Till name', detail: 'Till name, for example:', text: 'TILL-1' },
                    { type: 'click', target: 'add', label: 'Click Add terminal', detail: 'Click Add terminal. The till appears under Store terminals.' },
                ],
            },
        },
        {
            title: 'Issue a token',
            body: 'Under **Store terminals**, find your till and click **Enrollment token** on its row.',
            figure: {
                shot: 'terminals-before',
                alt: 'The Terminals page showing this browser is not enrolled and the terminals in the list.',
                marks: [
                    { type: 'look', target: 'this-browser', label: 'Not enrolled yet', detail: 'The box at the top says whether this browser is enrolled.' },
                    { type: 'click', target: 'token', label: 'Click Enrollment token', detail: 'Click Enrollment token on the till’s row.' },
                ],
            },
        },
        {
            title: 'Enroll this browser with the token',
            body: 'A one-time token appears. If you are at the till right now, click **Enroll this browser with it**. If the till is another computer, copy the token, sign in there, open **Terminals** and paste it into the **Enrollment token** box, then click **Enroll this browser**.',
            figure: {
                shot: 'terminals-token',
                alt: 'A new enrollment token shown once, with an Enroll this browser with it button.',
                marks: [
                    { type: 'look', target: 'token-box', label: 'The token', detail: 'The token is shown only once. Copy it now if the till is another computer.' },
                    { type: 'click', target: 'enroll-with', label: 'Click Enroll this browser with it', detail: 'If you are at the till, click Enroll this browser with it.' },
                ],
            },
            warning: 'Treat the token like a password. It works once and expires at the time shown under it. If it runs out, issue a new one.',
        },
        {
            title: 'Check that it says Enrolled',
            body: 'The **This browser** box now shows the terminal name, for example **DEMO-01**. The till can open a shift and sell from this browser.',
            figure: {
                shot: 'terminals-enrolled',
                alt: 'The This browser box showing the browser is enrolled as DEMO-01.',
                marks: [{ type: 'look', target: 'this-browser', label: 'Enrolled', detail: 'The browser is enrolled as the terminal shown here.' }],
            },
            tip: 'Enroll each till once. It stays enrolled until an admin revokes it, even after signing out. If a till is lost or stolen, click **Revoke** on its row to lock that browser out at once.',
        },
    ],
    done: 'The till is enrolled. A cashier can now open a shift and start selling.',
    next: ['open-shift', 'manage-users'],
};
