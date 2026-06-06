import { describe, it, expect, vi, afterEach } from 'vitest'
import { fetchFull } from './client'

const API = 'https://api.midominio.com/wp-json/infouno/v1/chat'

afterEach( () => vi.restoreAllMocks() )

describe( 'fetchFull', () => {
  it( 'POSTea a ?mode=full y devuelve reply', async () => {
    const spy = vi.spyOn( globalThis, 'fetch' ).mockResolvedValue(
      new Response( JSON.stringify( { reply: 'Hola PyME', status: 'complete' } ), { status: 200 } )
    )
    const reply = await fetchFull( API, 'tok', 'sess-1', 'hola', new AbortController().signal )

    expect( reply ).toBe( 'Hola PyME' )
    expect( spy.mock.calls[0][0] ).toContain( 'mode=full' )
  } )

  it( 'lanza con el message del servidor en error con código', async () => {
    vi.spyOn( globalThis, 'fetch' ).mockResolvedValue(
      new Response( JSON.stringify( { code: 402, message: 'Cuota agotada.' } ), { status: 402 } )
    )
    await expect(
      fetchFull( API, 'tok', 'sess-1', 'hola', new AbortController().signal )
    ).rejects.toThrow( 'Cuota agotada.' )
  } )
} )
