import { describe, it, expect, vi } from 'vitest'
import { deliverChat } from './deliver'
import type { StreamCallbacks } from './client'

const API = 'https://api.midominio.com/wp-json/infouno/v1/chat'

function collector() {
  const deltas: string[] = []
  let done = false
  let error = ''
  return {
    deltas, get done() { return done }, get error() { return error },
    cb: {
      onDelta: ( t: string ) => deltas.push( t ),
      onDone:  () => { done = true },
      onError: ( m: string ) => { error = m },
    },
  }
}

describe( 'deliverChat', () => {
  it( 'usa SSE cuando el primer chunk llega a tiempo y marca modo sse', async () => {
    const c = collector()
    const stored: Record<string, string> = {}
    const streamImpl = async ( _u: string, _t: string, _s: string, _m: string, cbs: StreamCallbacks ) => {
      cbs.onDelta( 'Hola ' ); cbs.onDelta( 'PyME' ); cbs.onDone()
    }
    const fullImpl = vi.fn()

    await deliverChat( {
      apiUrl: API, botToken: 't', sessionId: 's', message: 'hola',
      signal: new AbortController().signal,
      ...c.cb,
      firstChunkTimeoutMs: 50,
      streamImpl: streamImpl as never,
      fullImpl:   fullImpl as never,
      getMode: () => null,
      setMode: ( _u, mode ) => { stored.mode = mode },
    } )

    expect( c.deltas.join( '' ) ).toBe( 'Hola PyME' )
    expect( c.done ).toBe( true )
    expect( fullImpl ).not.toHaveBeenCalled()
    expect( stored.mode ).toBe( 'sse' )
  } )

  it( 'cae a full cuando el SSE no emite primer chunk a tiempo', async () => {
    const c = collector()
    const stored: Record<string, string> = {}
    const streamImpl = ( _u: string, _t: string, _s: string, _m: string, _cbs: StreamCallbacks, signal: AbortSignal ) =>
      new Promise<void>( ( resolve ) => { signal.addEventListener( 'abort', () => resolve(), { once: true } ) } )
    const fullImpl = async () => 'Respuesta completa'

    await deliverChat( {
      apiUrl: API, botToken: 't', sessionId: 's', message: 'hola',
      signal: new AbortController().signal,
      ...c.cb,
      firstChunkTimeoutMs: 20,
      streamImpl: streamImpl as never,
      fullImpl:   fullImpl as never,
      getMode: () => null,
      setMode: ( _u, mode ) => { stored.mode = mode },
    } )

    expect( c.deltas.join( '' ) ).toBe( 'Respuesta completa' )
    expect( c.done ).toBe( true )
    expect( stored.mode ).toBe( 'full' )
  } )

  it( 'va directo a full si el modo recordado es full y no toca reintentar', async () => {
    const c = collector()
    const streamImpl = vi.fn()
    const fullImpl = async () => 'Directo full'

    await deliverChat( {
      apiUrl: API, botToken: 't', sessionId: 's', message: 'hola',
      signal: new AbortController().signal,
      ...c.cb,
      streamImpl: streamImpl as never,
      fullImpl:   fullImpl as never,
      getMode: () => 'full',
      setMode: () => {},
      shouldRetrySse: () => false,
    } )

    expect( streamImpl ).not.toHaveBeenCalled()
    expect( c.deltas.join( '' ) ).toBe( 'Directo full' )
    expect( c.done ).toBe( true )
  } )

  it( 'reintenta SSE aunque el modo sea full y, si funciona, vuelve a marcar sse', async () => {
    const c = collector()
    const stored: Record<string, string> = {}
    const streamImpl = async ( _u: string, _t: string, _s: string, _m: string, cbs: StreamCallbacks ) => {
      cbs.onDelta( 'SSE de nuevo' ); cbs.onDone()
    }
    const fullImpl = vi.fn()

    await deliverChat( {
      apiUrl: API, botToken: 't', sessionId: 's', message: 'hola',
      signal: new AbortController().signal,
      ...c.cb,
      firstChunkTimeoutMs: 50,
      streamImpl: streamImpl as never,
      fullImpl:   fullImpl as never,
      getMode: () => 'full',
      setMode: ( _u, mode ) => { stored.mode = mode },
      shouldRetrySse: () => true,
    } )

    expect( c.deltas.join( '' ) ).toBe( 'SSE de nuevo' )
    expect( c.done ).toBe( true )
    expect( fullImpl ).not.toHaveBeenCalled()
    expect( stored.mode ).toBe( 'sse' )
  } )

  it( 'si el reintento SSE no emite a tiempo, vuelve a caer a full', async () => {
    const c = collector()
    const stored: Record<string, string> = {}
    const streamImpl = ( _u: string, _t: string, _s: string, _m: string, _cbs: StreamCallbacks, signal: AbortSignal ) =>
      new Promise<void>( ( resolve ) => { signal.addEventListener( 'abort', () => resolve(), { once: true } ) } )
    const fullImpl = async () => 'Full otra vez'

    await deliverChat( {
      apiUrl: API, botToken: 't', sessionId: 's', message: 'hola',
      signal: new AbortController().signal,
      ...c.cb,
      firstChunkTimeoutMs: 20,
      streamImpl: streamImpl as never,
      fullImpl:   fullImpl as never,
      getMode: () => 'full',
      setMode: ( _u, mode ) => { stored.mode = mode },
      shouldRetrySse: () => true,
    } )

    expect( c.deltas.join( '' ) ).toBe( 'Full otra vez' )
    expect( c.done ).toBe( true )
    expect( stored.mode ).toBe( 'full' )
  } )
} )
