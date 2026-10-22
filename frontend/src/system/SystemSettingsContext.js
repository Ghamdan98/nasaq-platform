import { createContext, useContext } from 'react'
export const SystemSettingsContext = createContext(null)
export const useSystemSettings = () => useContext(SystemSettingsContext)
