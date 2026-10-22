import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import './system/system.css'
import App from './App.jsx'
import { SystemSettingsProvider } from './system/SystemSettingsProvider'

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <SystemSettingsProvider><App /></SystemSettingsProvider>
  </StrictMode>,
)
