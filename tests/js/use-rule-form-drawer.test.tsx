import { act, renderHook } from '@testing-library/react';

import { useRuleFormDrawer } from '../../client/admin-settings/hooks/use-rule-form-drawer';

describe( 'useRuleFormDrawer', () => {
	it( 'moves between closed, create, edit, and closed states', () => {
		const { result } = renderHook( () => useRuleFormDrawer() );

		expect( result.current.isOpen ).toBe( false );
		expect( result.current.ruleId ).toBeUndefined();

		act( () => result.current.openCreateRule() );
		expect( result.current.isOpen ).toBe( true );
		expect( result.current.ruleId ).toBeUndefined();

		act( () => result.current.openEditRule( 7 ) );
		expect( result.current.isOpen ).toBe( true );
		expect( result.current.ruleId ).toBe( 7 );

		act( () => result.current.openEditRule( 11 ) );
		expect( result.current.ruleId ).toBe( 11 );

		act( () => result.current.closeRuleForm() );
		expect( result.current.isOpen ).toBe( false );
		expect( result.current.ruleId ).toBeUndefined();
	} );

	it( 'keeps separate hook instances independent', () => {
		const { result } = renderHook( () => ( {
			first: useRuleFormDrawer(),
			second: useRuleFormDrawer(),
		} ) );

		act( () => result.current.first.openEditRule( 5 ) );
		expect( result.current.first.isOpen ).toBe( true );
		expect( result.current.first.ruleId ).toBe( 5 );
		expect( result.current.second.isOpen ).toBe( false );
		expect( result.current.second.ruleId ).toBeUndefined();

		act( () => result.current.second.openCreateRule() );
		expect( result.current.first.ruleId ).toBe( 5 );
		expect( result.current.second.isOpen ).toBe( true );
		expect( result.current.second.ruleId ).toBeUndefined();
	} );
} );
