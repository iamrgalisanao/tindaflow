import { createRoot } from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import AppRouter from './AppRouter';
import '../css/app.css';

createRoot(document.getElementById('app')).render(
    <BrowserRouter>
        <AppRouter />
    </BrowserRouter>,
);
