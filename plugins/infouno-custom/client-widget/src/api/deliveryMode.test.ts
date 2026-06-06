import { describe, it, expect, beforeEach } from 'vitest'
import { getPreferredMode, setPreferredMode, hostKey } from './deliveryMode'

const API = 'https://api.midominio.com/wp-json/infouno/v1/chat'

describe( 'deliveryMode', () => {
  beforeEach( () => localStorage.clear() )

  it( 'hostKey extrae el host del apiUrl', () => {
    expect( hostKey( API ) ).toBe( 'api.midominio.com' )
  } )

  it( 'devuelve null si no hay nada guardado', () => {
    expect( getPreferredMode( API ) ).toBeNull()
  } )

  it( 'persiste y lee el modo por host', () => {
    setPreferredMode( API, 'full' )
    expect( getPreferredMode( API ) ).toBe( 'full' )
  } )

  it( 'ignora valores corruptos en storage', () => {
    localStorage.setItem( 'infouno_delivery_mode:api.midominio.com', 'basura' )
    expect( getPreferredMode( API ) ).toBeNull()
  } )
} )
