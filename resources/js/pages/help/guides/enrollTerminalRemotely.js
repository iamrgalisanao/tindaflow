export default {
    slug: 'enroll-till-remotely',
    category: 'setup',
    title: 'Enroll a till when the admin is somewhere else',
    summary: 'The person at the till computer asks for approval and reads out a code. An admin, anywhere, types that code and picks the till. No admin password is shared.',
    roles: ['ADMIN', 'MANAGER', 'CASHIER'],
    startHere: [],
    minutes: 4,
    before: [
        'There are two people: someone **at the till computer** (a cashier is fine) and an **admin** who can be at any other computer, or on a phone call.',
        'The till already exists in the **Terminals** list. If not, the admin adds it first (see **Enroll a till (terminal)**).',
        'The till computer opens the shop’s address **directly in Chrome or Edge**, not from a link inside Messenger or another app.',
    ],
    steps: [
        {
            title: 'At the till computer: sign in and ask',
            body: 'Sign in with your own account and open **POS / Till**. You will see **This browser is not enrolled as any terminal**. Under it, click **Ask an admin to approve this computer**.',
        },
        {
            title: 'At the till computer: read out the code',
            body: 'A big code like **ABCD-EFGH** appears and the page says **Waiting for an administrator**. Read the code to the admin (a phone call is enough). **Keep this page open.** It connects by itself once the admin approves.',
            tip: 'The code lasts 15 minutes. If it runs out, click **Cancel this request** and ask again.',
        },
        {
            title: 'As the admin: approve it',
            body: 'Open **Terminals**. Under **Tills waiting for approval** you will see the request with the person’s name and where it came from. Choose the **till** it should become, type the **code they read to you**, and click **Approve**.',
            warning: 'Only approve a code you were given by someone you know, for a computer you expect. The code is never shown on your screen on purpose: typing it is how you confirm the request is the right one. Click **Decline** for anything you do not recognize.',
        },
        {
            title: 'At the till computer: it connects',
            body: 'Within a few seconds the page reloads as the till. The cashier can open a shift and sell. The admin sees the notice **Approved. The computer asking will connect as …**.',
            tip: 'If the till you picked is already enrolled on another browser, approving replaces it: the old browser stops working as that till. The screen warns you before you approve.',
        },
    ],
    done: 'The computer is now the till, and nobody had to share an admin password. Every request and approval is recorded in the audit log.',
    next: ['open-shift', 'enroll-terminal'],
};
